<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Receiver;

use MiguelAlcaino\CloudTasksMessenger\Client\InMemoryCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Receiver\PushedMessageProcessor;
use MiguelAlcaino\CloudTasksMessenger\Receiver\PushResult;
use MiguelAlcaino\CloudTasksMessenger\Security\IdTokenVerifierInterface;
use MiguelAlcaino\CloudTasksMessenger\Security\RequestAuthenticator;
use MiguelAlcaino\CloudTasksMessenger\Stamp\CloudTasksReceivedStamp;
use MiguelAlcaino\CloudTasksMessenger\Test\PushRequestFactory;
use MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures\TestMessage;
use MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures\TestMessageHandler;
use MiguelAlcaino\CloudTasksMessenger\Transport\CloudTasksTransport;
use MiguelAlcaino\CloudTasksMessenger\Transport\Configuration;
use MiguelAlcaino\CloudTasksMessenger\Transport\RetryMode;
use MiguelAlcaino\CloudTasksMessenger\Transport\TaskPayload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class PushedMessageProcessorTest extends TestCase
{
    private const SECRET = 's3cret';
    private const QUEUE = 'projects/my-project/locations/europe-west1/queues/my-queue';
    private const MAX_RETRIES = 3;

    public function testInvalidBodyIsABadRequest(): void
    {
        $processor = $this->createProcessor($this->createTransport(new InMemoryCloudTasksClient()));

        $request = Request::create('https://app.example.com/_messenger/cloud-tasks', 'POST', [], [], [], [], 'not json');

        self::assertSame(PushResult::BadRequest, $processor->process($request));
    }

    public function testUnknownTransportIsRejected(): void
    {
        $client = new InMemoryCloudTasksClient();
        $otherConfiguration = new Configuration('missing', 'my-project', 'europe-west1', 'my-queue', 'https://app.example.com/_messenger/cloud-tasks', authSecret: self::SECRET);
        $otherTransport = new CloudTasksTransport($otherConfiguration, $client, new PhpSerializer(), new MockClock());
        $otherTransport->send(new Envelope(new TestMessage()));
        $processor = $this->createProcessor($this->createTransport($client));

        self::assertSame(PushResult::UnknownTransport, $processor->process($this->pushRequest($client)));
    }

    public function testTransportThatIsNotCloudTasksIsRejected(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client, transportName: 'failed');
        $transport->send(new Envelope(new TestMessage()));
        $processor = $this->createProcessor($this->createTransport($client));

        self::assertSame(PushResult::UnknownTransport, $processor->process($this->pushRequest($client)));
    }

    public function testUnauthenticatedRequestIsRejectedBeforeDecoding(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client);
        $transport->send(new Envelope(new TestMessage()));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $processor = $this->createProcessor($transport, bus: $bus);

        $request = $this->pushRequest($client);
        $request->headers->set(CloudTasksTransport::SECRET_HEADER, 'guess');

        self::assertSame(PushResult::Unauthorized, $processor->process($request));
    }

    public function testUndecodableMessageAsksCloudTasksToRetry(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client);
        $processor = $this->createProcessor($transport);
        $body = json_encode(['transport' => 'async', 'headers' => [], 'body' => base64_encode('O:99:"Gone\Class":0:{}')], \JSON_THROW_ON_ERROR);

        $request = Request::create('https://app.example.com/_messenger/cloud-tasks', 'POST', [], [], [], [], $body);
        $request->headers->set(CloudTasksTransport::SECRET_HEADER, self::SECRET);

        self::assertSame(PushResult::DecodingFailed, $processor->process($request));
        self::assertSame(500, PushResult::DecodingFailed->getStatusCode());
    }

    public function testMessageSkippedByAReceivedListenerIsNotHandled(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client);
        $transport->send(new Envelope(new TestMessage()));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, static fn (WorkerMessageReceivedEvent $event) => $event->shouldHandle(false));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $processor = $this->createProcessor($transport, bus: $bus, dispatcher: $dispatcher);

        self::assertSame(PushResult::Skipped, $processor->process($this->pushRequest($client)));
    }

    public function testRetryableFailureIsLeftToCloudTasks(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client);
        $transport->send(new Envelope(new TestMessage(TestMessage::FAIL)));
        $failed = new InMemoryTransport();
        $processor = $this->createProcessor($transport, failureTransport: $failed);

        $result = $processor->process($this->pushRequest($client, retryCount: 1));

        self::assertSame(PushResult::RetryRequested, $result);
        self::assertSame(500, $result->getStatusCode());
        self::assertSame([], $client->getTasks(), 'No new task: Cloud Tasks retries the same one.');
        self::assertSame([], $failed->getSent());
    }

    public function testExhaustedRetriesGoToTheFailureTransport(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client);
        $transport->send(new Envelope(new TestMessage(TestMessage::FAIL)));
        $failed = new InMemoryTransport();
        $processor = $this->createProcessor($transport, failureTransport: $failed);

        $result = $processor->process($this->pushRequest($client, retryCount: self::MAX_RETRIES));

        self::assertSame(PushResult::Rejected, $result);
        self::assertSame(200, $result->getStatusCode());
        self::assertCount(1, $failed->getSent());
        self::assertSame([], $client->getTasks());
    }

    public function testUnrecoverableFailureGoesToTheFailureTransportOnTheFirstAttempt(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client);
        $transport->send(new Envelope(new TestMessage(TestMessage::FAIL_UNRECOVERABLE)));
        $failed = new InMemoryTransport();
        $failedEvents = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$failedEvents): void {
            $failedEvents[] = $event;
        });
        $processor = $this->createProcessor($transport, dispatcher: $dispatcher, failureTransport: $failed);

        $result = $processor->process($this->pushRequest($client));

        self::assertSame(PushResult::Rejected, $result);
        self::assertCount(1, $failed->getSent());
        self::assertCount(1, $failedEvents);
        self::assertSame('async', $failedEvents[0]->getReceiverName());
    }

    public function testMessengerRetryModeSchedulesANewDelayedTask(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client, retryMode: RetryMode::Messenger);
        $transport->send(new Envelope(new TestMessage(TestMessage::FAIL)));
        $processor = $this->createProcessor($transport);

        $result = $processor->process($this->pushRequest($client, retryCount: 5));

        self::assertSame(PushResult::RetryScheduled, $result);
        self::assertSame(200, $result->getStatusCode());
        $tasks = $client->getTasks();
        self::assertCount(1, $tasks);
        self::assertNotNull($tasks[0]->getScheduleTime(), 'The retry is delayed by the retry strategy.');
        $retried = (new PhpSerializer())->decode(
            TaskPayload::fromJson((string) $tasks[0]->getHttpRequest()?->getBody())->toEncodedEnvelope(),
        );
        self::assertSame(1, $retried->last(RedeliveryStamp::class)?->getRetryCount(), 'Cloud Tasks retries are not counted in messenger mode.');
    }

    public function testHandledMessageIsDispatchedLikeAWorkerWould(): void
    {
        $client = new InMemoryCloudTasksClient();
        $transport = $this->createTransport($client);
        $transport->send(new Envelope(new TestMessage(TestMessage::SUCCEED, 'abc')));
        $handler = new TestMessageHandler();
        $handledEvents = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $event) use (&$handledEvents): void {
            $handledEvents[] = $event;
        });
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([TestMessage::class => [$handler]]))]);
        $processor = $this->createProcessor($transport, bus: $bus, dispatcher: $dispatcher);

        $result = $processor->process($this->pushRequest($client, retryCount: 2));

        self::assertSame(PushResult::Handled, $result);
        self::assertCount(1, $handler->handled);
        self::assertSame('abc', $handler->handled[0]->id);
        self::assertCount(1, $handledEvents);
        $envelope = $handledEvents[0]->getEnvelope();
        self::assertSame('async', $envelope->last(ReceivedStamp::class)?->getTransportName());
        self::assertNotNull($envelope->last(ConsumedByWorkerStamp::class));
        self::assertSame(2, $envelope->last(RedeliveryStamp::class)?->getRetryCount());
        $received = $envelope->last(CloudTasksReceivedStamp::class);
        self::assertNotNull($received);
        self::assertSame(2, $received->retryCount);
        self::assertStringStartsWith(self::QUEUE.'/tasks/', (string) $envelope->last(TransportMessageIdStamp::class)?->getId());
    }

    private function pushRequest(InMemoryCloudTasksClient $client, int $retryCount = 0): Request
    {
        $tasks = $client->flush();
        self::assertCount(1, $tasks);

        return PushRequestFactory::fromTask($tasks[0], $retryCount);
    }

    private function createTransport(
        InMemoryCloudTasksClient $client,
        RetryMode $retryMode = RetryMode::CloudTasks,
        string $transportName = 'async',
    ): CloudTasksTransport {
        $configuration = new Configuration(
            transportName: $transportName,
            projectId: 'my-project',
            location: 'europe-west1',
            queue: 'my-queue',
            handlerUrl: 'https://app.example.com/_messenger/cloud-tasks',
            authSecret: self::SECRET,
            retryMode: $retryMode,
        );

        return new CloudTasksTransport($configuration, $client, new PhpSerializer(), new MockClock());
    }

    private function createProcessor(
        CloudTasksTransport $transport,
        ?MessageBusInterface $bus = null,
        ?EventDispatcher $dispatcher = null,
        ?InMemoryTransport $failureTransport = null,
    ): PushedMessageProcessor {
        $failureTransport ??= new InMemoryTransport();
        $dispatcher ??= new EventDispatcher();

        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn () => $transport]),
            new ServiceLocator(['async' => static fn () => new MultiplierRetryStrategy(self::MAX_RETRIES, 1000, 2)]),
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            new ServiceLocator(['async' => static fn () => $failureTransport]),
        ));

        $bus ??= new MessageBus([new HandleMessageMiddleware(new HandlersLocator([TestMessage::class => [new TestMessageHandler()]]))]);

        return new PushedMessageProcessor(
            new ServiceLocator([
                'async' => static fn () => $transport,
                'failed' => static fn () => $failureTransport,
            ]),
            $bus,
            new RequestAuthenticator($this->createMock(IdTokenVerifierInterface::class)),
            $dispatcher,
        );
    }
}
