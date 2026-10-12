<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Stamp;

use MiguelAlcaino\CloudTasksMessenger\Stamp\TaskNameStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;

final class TaskNameStampTest extends TestCase
{
    public function testRejectsInvalidCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaskNameStamp('order/42');
    }

    public function testRejectsTooLongIds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaskNameStamp(str_repeat('a', 501));
    }

    public function testFromKeyHashesAnyKeyIntoAValidStableId(): void
    {
        $stamp = TaskNameStamp::fromKey('order #42 / confirmation');

        self::assertSame(hash('sha256', 'order #42 / confirmation'), $stamp->taskId);
        self::assertSame($stamp->taskId, TaskNameStamp::fromKey('order #42 / confirmation')->taskId);
    }
}
