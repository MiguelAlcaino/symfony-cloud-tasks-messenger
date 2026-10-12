<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Transport;

use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Duration;
use Google\Protobuf\Timestamp;
use MiguelAlcaino\CloudTasksMessenger\Client\CloudTasksClientInterface;
use MiguelAlcaino\CloudTasksMessenger\Client\TaskAlreadyExistsException;
use MiguelAlcaino\CloudTasksMessenger\Stamp\CloudTasksReceivedStamp;
use MiguelAlcaino\CloudTasksMessenger\Stamp\TaskNameStamp;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Sends each message as an HTTP task to Cloud Tasks. Cloud Tasks then pushes it to the handler URL,
 * where PushedMessageProcessor handles it like a worker would.
 *
 * Receiving is push-only: get() yields nothing, ack() and reject() have nothing to do because Cloud
 * Tasks deletes the task when the push endpoint answers 2xx.
 */
final class CloudTasksTransport implements TransportInterface
{
    public const SECRET_HEADER = 'X-Cloud-Tasks-Messenger-Secret';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly CloudTasksClientInterface $client,
        private readonly SerializerInterface $serializer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function decode(TaskPayload $payload): Envelope
    {
        return $this->serializer->decode($payload->toEncodedEnvelope());
    }

    public function send(Envelope $envelope): Envelope
    {
        if ($this->isRetryLeftToCloudTasks($envelope)) {
            // The push endpoint answers 500 for this delivery and Cloud Tasks retries the same task.
            return $envelope;
        }

        $request = $this->buildCreateTaskRequest($envelope);
        $taskName = $request->getTask()?->getName() ?? '';

        try {
            $task = $this->client->createTask($request);
        } catch (TaskAlreadyExistsException $e) {
            $this->logger->info('Cloud Task {task} already exists, message {class} deduplicated.', [
                'task' => $taskName,
                'class' => $envelope->getMessage()::class,
            ]);

            return $envelope->with(new TransportMessageIdStamp($taskName));
        } catch (\Throwable $e) {
            throw new TransportException(\sprintf('Could not create the Cloud Task on queue "%s": %s', $this->configuration->getQueuePath(), $e->getMessage()), 0, $e);
        }

        return $envelope->with(new TransportMessageIdStamp($task->getName()));
    }

    public function buildCreateTaskRequest(Envelope $envelope): CreateTaskRequest
    {
        $configuration = $this->configuration;

        $encoded = $this->serializer->encode($envelope);
        $payload = TaskPayload::fromEncodedEnvelope($configuration->transportName, $encoded);

        $headers = ['Content-Type' => 'application/json'];
        if (null !== $configuration->authSecret) {
            $headers[self::SECRET_HEADER] = $configuration->authSecret;
        }

        $httpRequest = (new HttpRequest())
            ->setUrl($configuration->handlerUrl)
            ->setHttpMethod(HttpMethod::POST)
            ->setHeaders($headers)
            ->setBody($payload->toJson());

        if (null !== $configuration->serviceAccount) {
            $httpRequest->setOidcToken(
                (new OidcToken())
                    ->setServiceAccountEmail($configuration->serviceAccount)
                    ->setAudience((string) $configuration->audience),
            );
        }

        $task = (new Task())->setHttpRequest($httpRequest);

        $delay = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;
        if ($delay > 0) {
            $task->setScheduleTime($this->scheduleTime($delay));
        }

        if (null !== $configuration->dispatchDeadline) {
            $task->setDispatchDeadline((new Duration())->setSeconds($configuration->dispatchDeadline));
        }

        $nameStamp = $envelope->last(TaskNameStamp::class);
        if (null !== $nameStamp && null === $envelope->last(RedeliveryStamp::class)) {
            $task->setName($configuration->getQueuePath().'/tasks/'.$nameStamp->taskId);
        }

        return (new CreateTaskRequest())
            ->setParent($configuration->getQueuePath())
            ->setTask($task);
    }

    /**
     * Push-only transport: there is nothing to pull. Returning nothing keeps "messenger:consume"
     * idle instead of failing.
     */
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    /**
     * True when Messenger's retry listener re-sends the message currently delivered by this transport
     * and the transport is in "cloud_tasks" retry mode.
     */
    private function isRetryLeftToCloudTasks(Envelope $envelope): bool
    {
        if (RetryMode::CloudTasks !== $this->configuration->retryMode) {
            return false;
        }

        $received = $envelope->last(CloudTasksReceivedStamp::class);

        return null !== $received
            && $received->transportName === $this->configuration->transportName
            && null !== $envelope->last(RedeliveryStamp::class)
            && null === $envelope->last(SentToFailureTransportStamp::class);
    }

    private function scheduleTime(int $delayMs): Timestamp
    {
        $at = $this->clock->now()->modify(\sprintf('+%d milliseconds', $delayMs));

        return (new Timestamp())
            ->setSeconds($at->getTimestamp())
            ->setNanos((int) $at->format('u') * 1000);
    }
}
