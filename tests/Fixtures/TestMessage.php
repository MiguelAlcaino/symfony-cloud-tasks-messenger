<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures;

final class TestMessage
{
    public const SUCCEED = 'succeed';
    public const FAIL = 'fail';
    public const FAIL_UNRECOVERABLE = 'fail_unrecoverable';

    public function __construct(
        public readonly string $behaviour = self::SUCCEED,
        public readonly string $id = 'message-1',
    ) {
    }
}
