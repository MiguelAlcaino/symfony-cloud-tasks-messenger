<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Client;

use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\Task;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records created tasks instead of calling Google. Meant for the test suites of consuming apps.
 *
 * Task names are honoured: creating a task whose name was already used throws
 * TaskAlreadyExistsException, like Cloud Tasks does. Unnamed tasks get a generated name.
 */
final class InMemoryCloudTasksClient implements CloudTasksClientInterface, ResetInterface
{
    /** @var list<Task> */
    private array $tasks = [];

    /** @var array<string, true> */
    private array $usedNames = [];

    private int $sequence = 0;

    public function createTask(CreateTaskRequest $request): Task
    {
        $task = $request->getTask();
        if (null === $task) {
            throw new \InvalidArgumentException('The CreateTaskRequest has no task.');
        }

        $task = clone $task;
        $name = $task->getName();
        if ('' === $name) {
            $name = \sprintf('%s/tasks/in-memory-%d', $request->getParent(), ++$this->sequence);
            $task->setName($name);
        }

        if (isset($this->usedNames[$name])) {
            throw new TaskAlreadyExistsException($name);
        }

        $this->usedNames[$name] = true;
        $this->tasks[] = $task;

        return $task;
    }

    /**
     * Tasks created so far, oldest first.
     *
     * @return list<Task>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * Returns the pending tasks and forgets them. Used names are kept, as Cloud Tasks keeps them.
     *
     * @return list<Task>
     */
    public function flush(): array
    {
        $tasks = $this->tasks;
        $this->tasks = [];

        return $tasks;
    }

    public function reset(): void
    {
        $this->tasks = [];
        $this->usedNames = [];
        $this->sequence = 0;
    }
}
