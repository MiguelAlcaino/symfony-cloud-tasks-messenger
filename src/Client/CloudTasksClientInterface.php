<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Client;

use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\Task;

/**
 * The only Cloud Tasks call the transport makes. Swap the implementation to test without Google.
 */
interface CloudTasksClientInterface
{
    /**
     * @throws TaskAlreadyExistsException when a task with the same name exists or existed recently
     */
    public function createTask(CreateTaskRequest $request): Task;
}
