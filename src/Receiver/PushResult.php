<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Receiver;

/**
 * Outcome of one push delivery. Cloud Tasks deletes the task on any 2xx status and retries it,
 * following the queue's retry configuration, on anything else.
 */
enum PushResult: string
{
    /** The message was handled. */
    case Handled = 'handled';

    /** A WorkerMessageReceivedEvent listener asked to skip the message. */
    case Skipped = 'skipped';

    /** Handling failed and Messenger re-sent the message as a new task (retry_mode: messenger). */
    case RetryScheduled = 'retry_scheduled';

    /** Handling failed for good: the message went to the failure transport, if one is configured. */
    case Rejected = 'rejected';

    /** Handling failed and Cloud Tasks must retry this task (retry_mode: cloud_tasks). */
    case RetryRequested = 'retry_requested';

    /** The serialized envelope could not be decoded (maybe a deploy is in progress): let Cloud Tasks retry. */
    case DecodingFailed = 'decoding_failed';

    /** The request body is not a task payload. */
    case BadRequest = 'bad_request';

    /** The payload names a transport that does not exist or is not a Cloud Tasks transport. */
    case UnknownTransport = 'unknown_transport';

    /** The request could not be authenticated as coming from Cloud Tasks. */
    case Unauthorized = 'unauthorized';

    public function getStatusCode(): int
    {
        return match ($this) {
            self::Handled, self::Skipped, self::RetryScheduled, self::Rejected => 200,
            // Not 503 nor 429: Cloud Tasks throttles the whole queue on those.
            self::RetryRequested, self::DecodingFailed => 500,
            self::BadRequest => 400,
            self::UnknownTransport => 404,
            self::Unauthorized => 401,
        };
    }
}
