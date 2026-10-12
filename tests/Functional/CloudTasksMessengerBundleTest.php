<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Functional;

use Google\Cloud\Tasks\V2\Task;
use MiguelAlcaino\CloudTasksMessenger\Client\InMemoryCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Test\PushRequestFactory;
use MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures\TestMessage;
use MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures\TestMessageHandler;
use MiguelAlcaino\CloudTasksMessenger\Transport\CloudTasksTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CloudTasksMessengerBundleTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testUnauthenticatedPushIsRefused(): void
    {
        $task = $this->dispatchAndGetTask(new TestMessage());

        $request = PushRequestFactory::fromTask($task);
        $request->headers->remove(CloudTasksTransport::SECRET_HEADER);
        $response = $this->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->handler()->handled);
    }

    public function testRetryableFailureAnswers500UntilRetriesAreExhausted(): void
    {
        $task = $this->dispatchAndGetTask(new TestMessage(TestMessage::FAIL));

        $first = $this->handle(PushRequestFactory::fromTask($task, 0));
        $last = $this->handle(PushRequestFactory::fromTask($task, 2));

        self::assertSame(500, $first->getStatusCode());
        self::assertSame('{"status":"retry_requested"}', $first->getContent());
        self::assertSame(200, $last->getStatusCode());
        self::assertSame('{"status":"rejected"}', $last->getContent());
        self::assertCount(1, $this->failedTransport()->getSent());
        self::assertSame([], $this->client()->getTasks());
    }

    public function testUnrecoverableFailureGoesStraightToTheFailureTransport(): void
    {
        $task = $this->dispatchAndGetTask(new TestMessage(TestMessage::FAIL_UNRECOVERABLE));

        $response = $this->handle(PushRequestFactory::fromTask($task));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"rejected"}', $response->getContent());
        self::assertCount(1, $this->failedTransport()->getSent());
    }

    public function testMessengerRetryModeReEnqueuesADelayedTask(): void
    {
        $task = $this->dispatchAndGetTask(new TestMessage(TestMessage::FAIL), 'async_messenger_retry');

        $response = $this->handle(PushRequestFactory::fromTask($task));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"retry_scheduled"}', $response->getContent());
        $tasks = $this->client()->getTasks();
        self::assertCount(1, $tasks);
        self::assertNotNull($tasks[0]->getScheduleTime());
        self::assertSame([], $this->failedTransport()->getSent());
    }

    public function testDispatchedMessageIsHandledWhenCloudTasksPushesIt(): void
    {
        $task = $this->dispatchAndGetTask(new TestMessage(TestMessage::SUCCEED, 'abc'));

        $response = $this->handle(PushRequestFactory::fromTask($task));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"handled"}', $response->getContent());
        self::assertCount(1, $this->handler()->handled);
        self::assertSame('abc', $this->handler()->handled[0]->id);
    }

    private function dispatchAndGetTask(TestMessage $message, ?string $transport = null): Task
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new Envelope($message, null === $transport ? [] : [new TransportNamesStamp([$transport])]));

        $tasks = $this->client()->flush();
        self::assertCount(1, $tasks);
        self::assertSame([], $this->handler()->handled, 'Dispatching only creates the task.');

        return $tasks[0];
    }

    private function handle(Request $request): Response
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return $kernel->handle($request);
    }

    private function client(): InMemoryCloudTasksClient
    {
        $client = self::getContainer()->get(InMemoryCloudTasksClient::class);
        self::assertInstanceOf(InMemoryCloudTasksClient::class, $client);

        return $client;
    }

    private function handler(): TestMessageHandler
    {
        $handler = self::getContainer()->get(TestMessageHandler::class);
        self::assertInstanceOf(TestMessageHandler::class, $handler);

        return $handler;
    }

    private function failedTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
