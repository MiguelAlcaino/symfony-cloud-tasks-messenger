<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Client;

final class TaskAlreadyExistsException extends \RuntimeException
{
    public function __construct(
        public readonly string $taskName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('The Cloud Task "%s" already exists or existed recently.', $taskName), 0, $previous);
    }
}
