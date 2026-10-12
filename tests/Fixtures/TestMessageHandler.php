<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class TestMessageHandler
{
    /** @var list<TestMessage> */
    public array $handled = [];

    public function __invoke(TestMessage $message): void
    {
        if (TestMessage::FAIL === $message->behaviour) {
            throw new \RuntimeException('Temporary failure.');
        }
        if (TestMessage::FAIL_UNRECOVERABLE === $message->behaviour) {
            throw new UnrecoverableMessageHandlingException('Permanent failure.');
        }

        $this->handled[] = $message;
    }
}
