<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Added by the push endpoint to an envelope delivered by Cloud Tasks.
 */
final class CloudTasksReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly string $transportName,
        public readonly ?string $taskName,
        public readonly int $retryCount,
        public readonly int $executionCount,
    ) {
    }
}
