<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Transport;

use Google\Cloud\Tasks\V2\HttpMethod;
use MiguelAlcaino\CloudTasksMessenger\Client\CloudTasksClientInterface;
use MiguelAlcaino\CloudTasksMessenger\Client\InMemoryCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Stamp\CloudTasksReceivedStamp;
use MiguelAlcaino\CloudTasksMessenger\Stamp\TaskNameStamp;
use MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures\TestMessage;
use MiguelAlcaino\CloudTasksMessenger\Transport\CloudTasksTransport;
use MiguelAlcaino\CloudTasksMessenger\Transport\Configuration;
use MiguelAlcaino\CloudTasksMessenger\Transport\RetryMode;
use MiguelAlcaino\CloudTasksMessenger\Transport\TaskPayload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class CloudTasksTransportTest extends TestCase
{
    private const QUEUE = 'projects/my-project/locations/europe-west1/queues/my-queue';
    private const HANDLER_URL = 'https://app.example.com/_messenger/cloud-tasks';
    private const SERVICE_ACCOUNT = 'tasks@my-project.iam.gserviceaccount.com';

    public function testRetryOfTheMessageBeingDeliveredIsLeftToCloudTasks(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client, $this->createConfiguration());
        $envelope = new Envelope(new TestMessage(), [
            new CloudTasksReceivedStamp('async', 'task-1', 0, 0),
            new RedeliveryStamp(1),
            new DelayStamp(1000),
        ]);

        $sent = $transport->send($envelope);

        self::assertSame($envelope, $sent);
        self::assertSame([], $client->getTasks());
    }

    public function testDeduplicatedSendIsASuccess(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client, $this->createConfiguration());
        $transport->send(new Envelope(new TestMessage(), [new TaskNameStamp('order-42')]));

        $sent = $transport->send(new Envelope(new TestMessage(), [new TaskNameStamp('order-42')]));

        self::assertCount(1, $client->getTasks());
        self::assertSame(self::QUEUE.'/tasks/order-42', $sent->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testClientFailureBecomesATransportException(): void
    {
        $client = $this->createMock(CloudTasksClientInterface::class);
        $client->method('createTask')->willThrowException(new \RuntimeException('PERMISSION_DENIED'));
        $transport = $this->createTransport($client, $this->createConfiguration());

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not create the Cloud Task on queue "'.self::QUEUE.'": PERMISSION_DENIED');

        $transport->send(new Envelope(new TestMessage()));
    }

    public function testSendCreatesAnOidcAuthenticatedHttpTask(): void
    {
        $client = new InMemoryCloudTasksClient();
        $serializer = new PhpSerializer();
        $transport = $this->createTransport($client, $this->createConfiguration(), $serializer);

        $sent = $transport->send(new Envelope(new TestMessage(TestMessage::SUCCEED, 'abc')));

        $tasks = $client->getTasks();
        self::assertCount(1, $tasks);
        $task = $tasks[0];
        self::assertSame($task->getName(), $sent->last(TransportMessageIdStamp::class)?->getId());
        self::assertStringStartsWith(self::QUEUE.'/tasks/', $task->getName());
        self::assertNull($task->getScheduleTime());
        self::assertNull($task->getDispatchDeadline());

        $httpRequest = $task->getHttpRequest();
        self::assertNotNull($httpRequest);
        self::assertSame(self::HANDLER_URL, $httpRequest->getUrl());
        self::assertSame(HttpMethod::POST, $httpRequest->getHttpMethod());
        self::assertSame(['Content-Type' => 'application/json'], iterator_to_array($httpRequest->getHeaders()));
        self::assertSame(self::SERVICE_ACCOUNT, $httpRequest->getOidcToken()?->getServiceAccountEmail());
        self::assertSame(self::HANDLER_URL, $httpRequest->getOidcToken()->getAudience());

        $payload = TaskPayload::fromJson($httpRequest->getBody());
        self::assertSame('async', $payload->transportName);
        $message = $serializer->decode($payload->toEncodedEnvelope())->getMessage();
        self::assertInstanceOf(TestMessage::class, $message);
        self::assertSame('abc', $message->id);
    }

    public function testSendHonoursDelayTaskNameDeadlineAndSecret(): void
    {
        $client = new InMemoryCloudTasksClient();
        $configuration = new Configuration(
            transportName: 'async',
            projectId: 'my-project',
            location: 'europe-west1',
            queue: 'my-queue',
            handlerUrl: self::HANDLER_URL,
            authSecret: 's3cret',
            dispatchDeadline: 900,
        );
        $clock = new MockClock('2026-10-12 10:00:00.250000 UTC');
        $transport = $this->createTransport($client, $configuration, clock: $clock);

        $transport->send(new Envelope(new TestMessage(), [new DelayStamp(90_500), new TaskNameStamp('order-42')]));

        $task = $client->getTasks()[0];
        self::assertSame(self::QUEUE.'/tasks/order-42', $task->getName());
        $scheduleTime = $task->getScheduleTime();
        self::assertNotNull($scheduleTime);
        self::assertSame((new \DateTimeImmutable('2026-10-12 10:01:30 UTC'))->getTimestamp(), (int) $scheduleTime->getSeconds());
        self::assertSame(750_000_000, $scheduleTime->getNanos());
        self::assertSame(900, (int) $task->getDispatchDeadline()?->getSeconds());
        $httpRequest = $task->getHttpRequest();
        self::assertNotNull($httpRequest);
        self::assertNull($httpRequest->getOidcToken());
        self::assertSame(
            ['Content-Type' => 'application/json', CloudTasksTransport::SECRET_HEADER => 's3cret'],
            iterator_to_array($httpRequest->getHeaders()),
        );
    }

    public function testTaskNameIsIgnoredWhenMessengerResendsForRetry(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client, $this->createConfiguration(RetryMode::Messenger));

        $transport->send(new Envelope(new TestMessage(), [
            new TaskNameStamp('order-42'),
            new CloudTasksReceivedStamp('async', 'order-42', 0, 0),
            new RedeliveryStamp(1),
            new DelayStamp(2000),
        ]));

        $task = $client->getTasks()[0];
        self::assertStringEndsNotWith('/tasks/order-42', $task->getName());
        self::assertNotNull($task->getScheduleTime());
    }

    public function testSendingToTheFailureTransportIsNeverSwallowed(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client, $this->createConfiguration());

        $transport->send(new Envelope(new TestMessage(), [
            new CloudTasksReceivedStamp('async', 'task-1', 3, 3),
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(0),
        ]));

        self::assertCount(1, $client->getTasks());
    }

    public function testMessagesReceivedByAnotherTransportAreSent(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client, $this->createConfiguration());

        $transport->send(new Envelope(new TestMessage(), [
            new CloudTasksReceivedStamp('other', 'task-1', 1, 1),
            new RedeliveryStamp(1),
        ]));

        self::assertCount(1, $client->getTasks());
    }

    public function testReceivingIsPushOnly(): void
    {
        $client = $this->createMock(CloudTasksClientInterface::class);
        $client->expects(self::never())->method('createTask');
        $transport = $this->createTransport($client, $this->createConfiguration());
        $envelope = new Envelope(new TestMessage());

        $transport->ack($envelope);
        $transport->reject($envelope);

        self::assertSame([], $transport->get());
    }

    public function testCreateTaskRequestTargetsTheQueue(): void
    {
        $transport = $this->createTransport(new InMemoryCloudTasksClient(), $this->createConfiguration());

        $request = $transport->buildCreateTaskRequest(new Envelope(new TestMessage()));

        self::assertSame(self::QUEUE, $request->getParent());
        self::assertNotNull($request->getTask());
    }

    private function createConfiguration(RetryMode $retryMode = RetryMode::CloudTasks): Configuration
    {
        return new Configuration(
            transportName: 'async',
            projectId: 'my-project',
            location: 'europe-west1',
            queue: 'my-queue',
            handlerUrl: self::HANDLER_URL,
            serviceAccount: self::SERVICE_ACCOUNT,
            retryMode: $retryMode,
        );
    }

    private function createTransport(
        CloudTasksClientInterface $client,
        Configuration $configuration,
        ?PhpSerializer $serializer = null,
        ?MockClock $clock = null,
    ): CloudTasksTransport {
        return new CloudTasksTransport($configuration, $client, $serializer ?? new PhpSerializer(), $clock ?? new MockClock());
    }
}
