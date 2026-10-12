<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Receiver;

use MiguelAlcaino\CloudTasksMessenger\Security\AuthenticationFailedException;
use MiguelAlcaino\CloudTasksMessenger\Security\RequestAuthenticator;
use MiguelAlcaino\CloudTasksMessenger\Stamp\CloudTasksReceivedStamp;
use MiguelAlcaino\CloudTasksMessenger\Transport\CloudTasksTransport;
use MiguelAlcaino\CloudTasksMessenger\Transport\RetryMode;
use MiguelAlcaino\CloudTasksMessenger\Transport\TaskPayload;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\EnvelopeAwareExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Handles one Cloud Tasks push the way Symfony's Worker handles a message pulled from a transport:
 * same stamps (ReceivedStamp, ConsumedByWorkerStamp), same bus, same worker events, so retry,
 * failure-transport, logging and Doctrine listeners behave as usual.
 */
final class PushedMessageProcessor
{
    public const TASK_NAME_HEADER = 'X-CloudTasks-TaskName';
    public const RETRY_COUNT_HEADER = 'X-CloudTasks-TaskRetryCount';
    public const EXECUTION_COUNT_HEADER = 'X-CloudTasks-TaskExecutionCount';

    private readonly LoggerInterface $logger;

    /**
     * @param ContainerInterface $receiverLocator transports by name (FrameworkBundle's messenger.receiver_locator)
     */
    public function __construct(
        private readonly ContainerInterface $receiverLocator,
        private readonly MessageBusInterface $bus,
        private readonly RequestAuthenticator $authenticator,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request): PushResult
    {
        try {
            $payload = TaskPayload::fromJson($request->getContent());
        } catch (MessageDecodingFailedException $e) {
            $this->logger->warning('Rejected a Cloud Tasks push with an invalid body: {error}', ['error' => $e->getMessage()]);

            return PushResult::BadRequest;
        }

        $transportName = $payload->transportName;
        $transport = $this->receiverLocator->has($transportName) ? $this->receiverLocator->get($transportName) : null;
        if (!$transport instanceof CloudTasksTransport) {
            $this->logger->warning('Rejected a Cloud Tasks push for unknown transport {transport}.', ['transport' => $transportName]);

            return PushResult::UnknownTransport;
        }

        $configuration = $transport->getConfiguration();

        try {
            $this->authenticator->authenticate($request, $configuration);
        } catch (AuthenticationFailedException $e) {
            $this->logger->warning('Rejected an unauthenticated Cloud Tasks push for transport {transport}: {error}', [
                'transport' => $transportName,
                'error' => $e->getMessage(),
            ]);

            return PushResult::Unauthorized;
        }

        $taskName = $request->headers->get(self::TASK_NAME_HEADER);
        $retryCount = max(0, (int) $request->headers->get(self::RETRY_COUNT_HEADER, '0'));
        $executionCount = max(0, (int) $request->headers->get(self::EXECUTION_COUNT_HEADER, '0'));

        try {
            $envelope = $transport->decode($payload);
        } catch (MessageDecodingFailedException $e) {
            $this->logger->error('Could not decode the message of Cloud Task {task} on transport {transport}: {error}', [
                'task' => $taskName,
                'transport' => $transportName,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return PushResult::DecodingFailed;
        }

        $envelope = $envelope->with(new CloudTasksReceivedStamp($transportName, $taskName, $retryCount, $executionCount));
        if (null !== $taskName && '' !== $taskName) {
            // Same id as the one the sender stamped: the full task resource name.
            $envelope = $envelope->with(new TransportMessageIdStamp($configuration->getQueuePath().'/tasks/'.$taskName));
        }
        if (RetryMode::CloudTasks === $configuration->retryMode && $retryCount > RedeliveryStamp::getRetryCountFromEnvelope($envelope)) {
            // Cloud Tasks re-delivers the same task, so its retry count is the attempt count that
            // Messenger's retry strategy compares with max_retries.
            $envelope = $envelope->with(new RedeliveryStamp($retryCount));
        }

        $receivedEvent = new WorkerMessageReceivedEvent($envelope, $transportName);
        $this->eventDispatcher?->dispatch($receivedEvent);
        $envelope = $receivedEvent->getEnvelope();

        if (!$receivedEvent->shouldHandle()) {
            return PushResult::Skipped;
        }

        try {
            $envelope = $this->bus->dispatch($envelope->with(new ReceivedStamp($transportName), new ConsumedByWorkerStamp()));
        } catch (\Throwable $e) {
            return $this->handleFailure($transport, $transportName, $envelope, $e);
        }

        $handledEvent = new WorkerMessageHandledEvent($envelope, $transportName);
        $this->eventDispatcher?->dispatch($handledEvent);
        $envelope = $handledEvent->getEnvelope();

        $this->logger->info('{class} was handled successfully (Cloud Task {task}).', [
            'class' => $envelope->getMessage()::class,
            'task' => $taskName,
        ]);

        $transport->ack($envelope);

        return PushResult::Handled;
    }

    private function handleFailure(CloudTasksTransport $transport, string $transportName, Envelope $envelope, \Throwable $e): PushResult
    {
        if ($e instanceof HandlerFailedException) {
            $envelope = $e->getEnvelope();
        } elseif ($e instanceof EnvelopeAwareExceptionInterface && null !== $e->getEnvelope()) {
            $envelope = $e->getEnvelope();
        }

        // Listeners may throw here (failure transport down, re-send failed...): that bubbles up as a
        // 500 so Cloud Tasks retries instead of losing the message.
        $failedEvent = new WorkerMessageFailedEvent($envelope, $transportName, $e);
        $this->eventDispatcher?->dispatch($failedEvent);
        $envelope = $failedEvent->getEnvelope();

        $transport->reject($envelope);

        if (!$failedEvent->willRetry()) {
            return PushResult::Rejected;
        }

        return RetryMode::CloudTasks === $transport->getConfiguration()->retryMode
            ? PushResult::RetryRequested
            : PushResult::RetryScheduled;
    }
}
