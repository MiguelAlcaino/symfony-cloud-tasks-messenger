<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Test;

use Google\Cloud\Tasks\V2\Task;
use MiguelAlcaino\CloudTasksMessenger\Receiver\PushedMessageProcessor;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns a task recorded by InMemoryCloudTasksClient into the request Cloud Tasks would push, so a
 * test can deliver it to the kernel (or a KernelBrowser) and run the handler.
 *
 * The shared secret header travels with the task. An OIDC token cannot be produced offline: pass one
 * through $extraHeaders and replace the id token verifier in the test environment, or use
 * "auth_secret" in tests.
 */
final class PushRequestFactory
{
    /**
     * @param array<string, string> $extraHeaders
     */
    public static function fromTask(Task $task, int $retryCount = 0, array $extraHeaders = []): Request
    {
        $httpRequest = $task->getHttpRequest();
        if (null === $httpRequest) {
            throw new \InvalidArgumentException('Only HTTP tasks can be turned into a push request.');
        }

        $server = [];
        $headers = [
            PushedMessageProcessor::TASK_NAME_HEADER => self::shortName($task->getName()),
            PushedMessageProcessor::RETRY_COUNT_HEADER => (string) $retryCount,
            PushedMessageProcessor::EXECUTION_COUNT_HEADER => (string) $retryCount,
        ];
        foreach ($httpRequest->getHeaders() as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
        foreach ($extraHeaders + $headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server['content-type' === strtolower($name) ? 'CONTENT_TYPE' : 'HTTP_'.$key] = $value;
        }

        return Request::create($httpRequest->getUrl(), 'POST', [], [], [], $server, $httpRequest->getBody());
    }

    /**
     * Cloud Tasks sends the short task id in X-CloudTasks-TaskName, not the full resource name.
     */
    private static function shortName(string $name): string
    {
        $position = strrpos($name, '/');

        return false === $position ? $name : substr($name, $position + 1);
    }
}
