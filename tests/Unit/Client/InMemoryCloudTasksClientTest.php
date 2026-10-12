<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Client;

use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\Task;
use MiguelAlcaino\CloudTasksMessenger\Client\InMemoryCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Client\TaskAlreadyExistsException;
use PHPUnit\Framework\TestCase;

final class InMemoryCloudTasksClientTest extends TestCase
{
    private const QUEUE = 'projects/p/locations/l/queues/q';

    public function testRejectsARequestWithoutTask(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new InMemoryCloudTasksClient())->createTask((new CreateTaskRequest())->setParent(self::QUEUE));
    }

    public function testRejectsAReusedName(): void
    {
        $client = new InMemoryCloudTasksClient();
        $client->createTask($this->request(self::QUEUE.'/tasks/a'));
        $client->flush();

        $this->expectException(TaskAlreadyExistsException::class);

        $client->createTask($this->request(self::QUEUE.'/tasks/a'));
    }

    public function testRecordsTasksAndNamesUnnamedOnes(): void
    {
        $client = new InMemoryCloudTasksClient();

        $first = $client->createTask($this->request());
        $second = $client->createTask($this->request(self::QUEUE.'/tasks/b'));

        self::assertSame(self::QUEUE.'/tasks/in-memory-1', $first->getName());
        self::assertSame(self::QUEUE.'/tasks/b', $second->getName());
        self::assertSame([$first, $second], $client->getTasks());
        self::assertSame([$first, $second], $client->flush());
        self::assertSame([], $client->getTasks());
    }

    public function testResetForgetsTasksAndNames(): void
    {
        $client = new InMemoryCloudTasksClient();
        $client->createTask($this->request(self::QUEUE.'/tasks/a'));

        $client->reset();
        $task = $client->createTask($this->request(self::QUEUE.'/tasks/a'));

        self::assertSame([$task], $client->getTasks());
    }

    private function request(string $name = ''): CreateTaskRequest
    {
        return (new CreateTaskRequest())->setParent(self::QUEUE)->setTask((new Task())->setName($name));
    }
}
