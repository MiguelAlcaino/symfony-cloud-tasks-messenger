<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Transport;

/**
 * Who schedules the next attempt when a handler fails and Messenger's retry strategy allows a retry.
 */
enum RetryMode: string
{
    /**
     * The push endpoint answers 500 and Cloud Tasks retries the same task following the queue's retry
     * configuration (backoff, max attempts). Messenger's retry strategy only decides whether a retry
     * happens, using X-CloudTasks-TaskRetryCount as the attempt count; its delay is not used.
     */
    case CloudTasks = 'cloud_tasks';

    /**
     * Messenger re-sends the message as a brand-new task delayed by the retry strategy's waiting time
     * (exactly like any other transport) and the push endpoint answers 200 for the failed task.
     */
    case Messenger = 'messenger';
}
