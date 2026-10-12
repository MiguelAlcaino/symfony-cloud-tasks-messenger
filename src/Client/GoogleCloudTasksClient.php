<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Client;

use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\Task;

/**
 * Adapter over the official Google client. The Google client is created on first use, so building the
 * container or sending nothing never touches credentials.
 */
final class GoogleCloudTasksClient implements CloudTasksClientInterface
{
    private ?CloudTasksClient $client;

    /**
     * @param array<string, mixed> $clientOptions options for Google\Cloud\Tasks\V2\Client\CloudTasksClient
     *                                            (credentials, apiEndpoint, transport...); empty means
     *                                            Application Default Credentials
     */
    public function __construct(
        private readonly array $clientOptions = [],
        ?CloudTasksClient $client = null,
    ) {
        $this->client = $client;
    }

    public function createTask(CreateTaskRequest $request): Task
    {
        try {
            return $this->getClient()->createTask($request);
        } catch (ApiException $e) {
            if ('ALREADY_EXISTS' === $e->getStatus()) {
                throw new TaskAlreadyExistsException($request->getTask()?->getName() ?? '', $e);
            }

            throw $e;
        }
    }

    private function getClient(): CloudTasksClient
    {
        return $this->client ??= new CloudTasksClient($this->clientOptions);
    }
}
