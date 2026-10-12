<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Stamp;

use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Gives the Cloud Task a deterministic id so that Cloud Tasks deduplicates it.
 *
 * A second task created with the same id while the first one exists, or for a while after it was
 * deleted or executed (up to about an hour, or longer), is rejected by Cloud Tasks. The transport
 * treats that rejection as a successful, deduplicated send.
 *
 * Only the id is given, the transport builds the full name from its queue. The stamp is ignored
 * when Messenger re-sends the message for a retry (the envelope carries a RedeliveryStamp), since a
 * retry must never be deduplicated against its own first attempt.
 */
final class TaskNameStamp implements StampInterface
{
    private const MAX_LENGTH = 500;

    public function __construct(
        public readonly string $taskId,
    ) {
        if (1 !== preg_match('/^[a-zA-Z0-9_-]+$/', $taskId) || \strlen($taskId) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(\sprintf('Invalid Cloud Tasks task id "%s": only letters, digits, "-" and "_" are allowed, at most %d characters.', $taskId, self::MAX_LENGTH));
        }
    }

    /**
     * Builds a valid task id from an arbitrary business key (order number, event id...). The key is
     * hashed, which also spreads ids evenly, as Cloud Tasks recommends against sequential task names.
     */
    public static function fromKey(string $key): self
    {
        return new self(hash('sha256', $key));
    }
}
