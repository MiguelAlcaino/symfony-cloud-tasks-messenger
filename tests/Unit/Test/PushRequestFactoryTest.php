<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Test;

use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\Task;
use MiguelAlcaino\CloudTasksMessenger\Test\PushRequestFactory;
use PHPUnit\Framework\TestCase;

final class PushRequestFactoryTest extends TestCase
{
    public function testRejectsTasksWithoutHttpRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PushRequestFactory::fromTask(new Task());
    }

    public function testBuildsTheRequestCloudTasksWouldPush(): void
    {
        $task = (new Task())
            ->setName('projects/p/locations/l/queues/q/tasks/order-42')
            ->setHttpRequest((new HttpRequest())
                ->setUrl('https://app.example.com/_messenger/cloud-tasks')
                ->setHeaders(['Content-Type' => 'application/json', 'X-Cloud-Tasks-Messenger-Secret' => 's3cret'])
                ->setBody('{"transport":"async"}'));

        $request = PushRequestFactory::fromTask($task, 3, ['Authorization' => 'Bearer token']);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://app.example.com/_messenger/cloud-tasks', $request->getUri());
        self::assertSame('{"transport":"async"}', $request->getContent());
        self::assertSame('application/json', $request->headers->get('Content-Type'));
        self::assertSame('s3cret', $request->headers->get('X-Cloud-Tasks-Messenger-Secret'));
        self::assertSame('Bearer token', $request->headers->get('Authorization'));
        self::assertSame('order-42', $request->headers->get('X-CloudTasks-TaskName'));
        self::assertSame('3', $request->headers->get('X-CloudTasks-TaskRetryCount'));
    }
}
