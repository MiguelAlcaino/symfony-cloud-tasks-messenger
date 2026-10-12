<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Client;

use Google\ApiCore\ApiException;
use Google\ApiCore\InsecureCredentialsWrapper;
use Google\ApiCore\Transport\TransportInterface;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\Task;
use GuzzleHttp\Promise\Create;
use MiguelAlcaino\CloudTasksMessenger\Client\GoogleCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Client\TaskAlreadyExistsException;
use PHPUnit\Framework\TestCase;

final class GoogleCloudTasksClientTest extends TestCase
{
    private const TASK = 'projects/p/locations/l/queues/q/tasks/order-42';

    public function testAlreadyExistsBecomesTaskAlreadyExistsException(): void
    {
        $client = new GoogleCloudTasksClient([], $this->googleClient(new ApiException('Requested entity already exists', 6, 'ALREADY_EXISTS')));

        try {
            $client->createTask($this->request());
            self::fail('Expected a TaskAlreadyExistsException.');
        } catch (TaskAlreadyExistsException $e) {
            self::assertSame(self::TASK, $e->taskName);
            self::assertInstanceOf(ApiException::class, $e->getPrevious());
        }
    }

    public function testOtherApiErrorsAreRethrown(): void
    {
        $client = new GoogleCloudTasksClient([], $this->googleClient(new ApiException('Denied', 7, 'PERMISSION_DENIED')));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Denied');

        $client->createTask($this->request());
    }

    public function testReturnsTheCreatedTask(): void
    {
        $created = (new Task())->setName(self::TASK);
        $client = new GoogleCloudTasksClient([], $this->googleClient($created));

        self::assertSame(self::TASK, $client->createTask($this->request())->getName());
    }

    private function googleClient(Task|\Throwable $outcome): CloudTasksClient
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('startUnaryCall')->willReturn(
            $outcome instanceof \Throwable ? Create::rejectionFor($outcome) : Create::promiseFor($outcome),
        );

        return new CloudTasksClient([
            'transport' => $transport,
            'credentials' => new InsecureCredentialsWrapper(),
        ]);
    }

    private function request(): CreateTaskRequest
    {
        return (new CreateTaskRequest())
            ->setParent('projects/p/locations/l/queues/q')
            ->setTask((new Task())->setName(self::TASK));
    }
}
