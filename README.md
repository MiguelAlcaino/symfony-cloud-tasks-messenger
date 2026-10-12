# Cloud Tasks Messenger

A [Symfony Messenger](https://symfony.com/doc/current/messenger.html) transport backed by
[Google Cloud Tasks](https://cloud.google.com/tasks/docs). Cloud Tasks pushes every message back to
your application over HTTP, so you get asynchronous, delayed and retried messages **without running
`messenger:consume`**.

[![CI](https://github.com/MiguelAlcaino/symfony-cloud-tasks-messenger/actions/workflows/ci.yml/badge.svg)](https://github.com/MiguelAlcaino/symfony-cloud-tasks-messenger/actions/workflows/ci.yml)

## Why

Messenger's transports are pull-based: something has to run `messenger:consume` all the time. On
[Cloud Run](https://cloud.google.com/run) (or any platform that only runs code while it serves a
request) there is no good place for that long-running worker.

Cloud Tasks turns the queue around. Sending a message creates an HTTP task, and Cloud Tasks calls
your app with it, when it is due, retrying with backoff on failure. This bundle provides both sides:

- a **transport** (`cloud-tasks://...`) that creates one HTTP task per message, and
- a **push endpoint** that authenticates Cloud Tasks and handles the message exactly like a Messenger
  worker would: same stamps, same bus, same `WorkerMessage*` events, so retries, the failure
  transport, logging and Doctrine listeners keep working.

Switching an existing transport to Cloud Tasks only means changing its DSN. Message classes, handlers
and routing stay as they are.

## Requirements

- PHP 8.2+
- Symfony 6.4 or 7.x (Messenger, FrameworkBundle)
- `google/cloud-tasks` ^2 (installed as a dependency)

## Installation

```bash
composer require miguelalcaino/cloud-tasks-messenger
```

Register the bundle if Symfony Flex did not:

```php
// config/bundles.php
return [
    // ...
    MiguelAlcaino\CloudTasksMessenger\CloudTasksMessengerBundle::class => ['all' => true],
];
```

Import the push endpoint route:

```yaml
# config/routes/cloud_tasks_messenger.yaml
cloud_tasks_messenger:
    resource: '@CloudTasksMessengerBundle/config/routes.php'
    # prefix: /internal   # optional
```

This exposes `POST /_messenger/cloud-tasks` (route name `cloud_tasks_messenger_push`). The endpoint
authenticates every request itself (see [Authentication](#authentication)). If you use the Security
component, make sure no firewall in front of it requires a user, for example:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        cloud_tasks:
            pattern: ^/_messenger/cloud-tasks$
            security: false
```

## Configuration

### DSN

```
cloud-tasks://PROJECT_ID/LOCATION/QUEUE_ID?handler_url=URL[&service_account=EMAIL][&audience=AUDIENCE][&auth_secret=SECRET][&retry_mode=cloud_tasks|messenger][&dispatch_deadline=SECONDS]
```

Every query parameter can also be given in the transport's `options:`. When both are set, the DSN
wins.

| Option              | Required | Default        | Description |
|---------------------|----------|----------------|-------------|
| `handler_url`       | yes      |                | Absolute URL of the push endpoint, e.g. `https://my-app-xyz.a.run.app/_messenger/cloud-tasks`. |
| `service_account`   | one of   |                | Service account Cloud Tasks uses to sign an OIDC token for each push. The endpoint verifies the token. Recommended in production. |
| `audience`          | no       | `handler_url`  | Audience of the OIDC token. Use the service URL if Cloud Run checks the token too and you prefer a fixed audience. |
| `auth_secret`       | one of   |                | Shared secret sent in the `X-Cloud-Tasks-Messenger-Secret` header. Handy locally; anyone allowed to view tasks can read it. |
| `retry_mode`        | no       | `cloud_tasks`  | Who schedules retries, see [Retries](#retries-and-failures). |
| `dispatch_deadline` | no       | Cloud Tasks' (10 min) | How long Cloud Tasks waits for the endpoint to answer, 15 to 1800 seconds. Keep it below your Cloud Run request timeout. |

At least one of `service_account` and `auth_secret` is required. When both are set, both are checked.

### Example

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    handler_url: '%env(CLOUD_TASKS_HANDLER_URL)%'
                    service_account: '%env(CLOUD_TASKS_SERVICE_ACCOUNT)%'
                retry_strategy:
                    max_retries: 3
            failed: 'doctrine://default?queue_name=failed'
        routing:
            App\Message\SendWelcomeEmail: async
```

```dotenv
MESSENGER_TRANSPORT_DSN=cloud-tasks://my-project/europe-west1/messenger-async
CLOUD_TASKS_HANDLER_URL=https://my-app-xyz.a.run.app/_messenger/cloud-tasks
CLOUD_TASKS_SERVICE_ACCOUNT=cloud-tasks-invoker@my-project.iam.gserviceaccount.com
```

### Bundle options

All optional:

```yaml
# config/packages/cloud_tasks_messenger.yaml
cloud_tasks_messenger:
    # CloudTasksClientInterface service; "cloud_tasks_messenger.client.in_memory" for tests
    client: cloud_tasks_messenger.client.google
    # Options for Google\Cloud\Tasks\V2\Client\CloudTasksClient (credentials, apiEndpoint, ...).
    # Empty means Application Default Credentials, which is what you want on Cloud Run.
    google_client_options: {}
    # IdTokenVerifierInterface service that checks the OIDC tokens
    id_token_verifier: cloud_tasks_messenger.id_token_verifier.google
    # PSR-6 pool caching Google's public certificates (null to fetch them on every push)
    certificates_cache: cache.app
```

## Sending messages

Nothing changes: `$bus->dispatch($message)` creates a task on the queue the message is routed to.

- **Delays**: a `DelayStamp` becomes the task's `scheduleTime`. Cloud Tasks accepts up to 30 days.
- **Deduplication**: add a `TaskNameStamp` to give the task a deterministic id. Cloud Tasks refuses a
  second task with the same id while the first exists and for a while after it ran (about an hour);
  the transport treats that refusal as a successful send, so dispatching twice delivers once.

```php
use MiguelAlcaino\CloudTasksMessenger\Stamp\TaskNameStamp;

$bus->dispatch(new SendInvoice($invoiceId), [TaskNameStamp::fromKey('send-invoice-'.$invoiceId)]);
// or a ready-made id: new TaskNameStamp('send-invoice-42') (letters, digits, "-" and "_")
```

`TaskNameStamp::fromKey()` hashes the key, which also follows Google's advice against sequential task
names. The stamp is ignored when Messenger re-sends a message for a retry.

## Receiving messages

For every push, the endpoint:

1. authenticates the request against the transport named in the task;
2. decodes the envelope with that transport's serializer;
3. adds `ReceivedStamp(<transport>)`, `ConsumedByWorkerStamp`, a `TransportMessageIdStamp` with the
   task name and a `CloudTasksReceivedStamp` (task name, retry and execution counts);
4. dispatches `WorkerMessageReceivedEvent`, then the envelope through `messenger.routable_message_bus`
   (so a `BusNameStamp` picks the right bus), then `WorkerMessageHandledEvent` or
   `WorkerMessageFailedEvent`, like `Symfony\Component\Messenger\Worker` does.

| Outcome | HTTP status | Body `status` | Cloud Tasks |
|---|---|---|---|
| Handled | 200 | `handled` | deletes the task |
| Skipped by a `WorkerMessageReceivedEvent` listener | 200 | `skipped` | deletes the task |
| Failed, Messenger will retry, `retry_mode: cloud_tasks` | 500 | `retry_requested` | retries the task |
| Failed, Messenger will retry, `retry_mode: messenger` | 200 | `retry_scheduled` | deletes the task (a new delayed task was created) |
| Failed for good (unrecoverable or out of retries) | 200 | `rejected` | deletes the task; the message is in the failure transport, if any |
| Envelope cannot be decoded | 500 | `decoding_failed` | retries (a deploy may be rolling out) |
| Not a task payload | 400 | `bad_request` | retries up to the queue's max attempts |
| Unknown or non Cloud Tasks transport | 404 | `unknown_transport` | idem |
| Authentication failed | 401 | `unauthorized` | idem |

The endpoint never answers 429 or 503 on purpose: Cloud Tasks throttles the whole queue on those.
If a failure listener itself throws (for example the failure transport is down), the exception
propagates and the resulting 500 makes Cloud Tasks retry, so the message is not lost.

`messenger:consume` keeps working with this transport: `get()` simply returns nothing.

## Retries and failures

Messenger's `retry_strategy` of the transport always decides **whether** a failed message is retried,
and an `UnrecoverableMessageHandlingException` is never retried. `retry_mode` decides **how**:

- **`cloud_tasks`** (default). The endpoint answers 500 and Cloud Tasks retries the *same task*
  using the queue's retry configuration (`--min-backoff`, `--max-backoff`, `--max-doublings`).
  The attempt number Messenger compares with `max_retries` is the `X-CloudTasks-TaskRetryCount`
  header, which also counts attempts where your app never answered (crash, timeout). The strategy's
  `delay`/`multiplier` are not used. Once `max_retries` is reached, the message goes to the failure
  transport and the endpoint answers 200.

  Set the queue's `--max-attempts` **higher than `max_retries + 1`** (or unlimited, `-1`), otherwise
  Cloud Tasks gives up first and the message never reaches the failure transport.

- **`messenger`**. Exactly what other transports do: Messenger re-sends the message as a *new task*,
  delayed by the retry strategy, carrying a `RedeliveryStamp`; the endpoint answers 200. Cloud Tasks
  only retries a task when the endpoint does not answer at all.

The failure transport should be a pull transport you can inspect and replay (`doctrine://`, ...);
`messenger:failed:show` and `messenger:failed:retry` work as usual.

## Authentication

Configure `service_account` in production. Cloud Tasks then adds an OIDC ID token signed by Google
for that service account to every push, and the endpoint checks its signature, expiry, issuer
(`accounts.google.com`), audience (`audience`, default `handler_url`) and that it belongs to
`service_account` with a verified email.

If the Cloud Run service itself requires authentication, grant that service account
`roles/run.invoker` on it; the in-app check still runs, which also protects you if the service is
later made public.

`auth_secret` is a simpler alternative for local development or non-Google environments. It travels
in a task header, visible to anyone who can view the queue's tasks, so prefer OIDC in production.

To verify tokens differently (for example to accept a token from an emulator), implement
`MiguelAlcaino\CloudTasksMessenger\Security\IdTokenVerifierInterface` and set `id_token_verifier`.

## Google Cloud setup

```bash
PROJECT=my-project
LOCATION=europe-west1
QUEUE=messenger-async
APP_SA=my-app@$PROJECT.iam.gserviceaccount.com           # identity the app runs as (Cloud Run service account)
INVOKER_SA=cloud-tasks-invoker@$PROJECT.iam.gserviceaccount.com

# 1. The queue. Max attempts above Messenger's max_retries + 1 (or -1 for unlimited).
gcloud tasks queues create $QUEUE --project=$PROJECT --location=$LOCATION \
    --max-attempts=10 --min-backoff=10s --max-backoff=600s --max-doublings=4

# 2. The service account Cloud Tasks signs OIDC tokens as.
gcloud iam service-accounts create cloud-tasks-invoker --project=$PROJECT

# 3. The app may create tasks...
gcloud projects add-iam-policy-binding $PROJECT \
    --member=serviceAccount:$APP_SA --role=roles/cloudtasks.enqueuer

# ...and may create them "as" the invoker service account (needed for OIDC tokens).
gcloud iam service-accounts add-iam-policy-binding $INVOKER_SA --project=$PROJECT \
    --member=serviceAccount:$APP_SA --role=roles/iam.serviceAccountUser

# 4. Only if the Cloud Run service requires authentication:
gcloud run services add-iam-policy-binding my-app --project=$PROJECT --region=$LOCATION \
    --member=serviceAccount:$INVOKER_SA --role=roles/run.invoker
```

The Cloud Tasks service agent (`service-PROJECT_NUMBER@gcp-sa-cloudtasks.iam.gserviceaccount.com`)
mints the tokens; it has the permission it needs by default. On Cloud Run the Google client uses the
service's identity (Application Default Credentials), so no key file is needed. Elsewhere, set
`GOOGLE_APPLICATION_CREDENTIALS` or `google_client_options.credentials`.

## Testing your application

Two options:

1. In the test environment, point the transport at `in-memory://` (or `sync://`) as with any other
   transport. Simplest; the Cloud Tasks code is not involved.
2. Keep the `cloud-tasks://` DSN and swap the Google client for the in-memory one, which records the
   tasks instead of calling Google. `PushRequestFactory` turns a recorded task into the request Cloud
   Tasks would send, to run the full round trip through your kernel. Use `auth_secret` in the test
   environment (an OIDC token cannot be produced offline), or a custom `id_token_verifier`.

```yaml
# config/packages/test/cloud_tasks_messenger.yaml
cloud_tasks_messenger:
    client: cloud_tasks_messenger.client.in_memory
```

```php
use MiguelAlcaino\CloudTasksMessenger\Client\InMemoryCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Test\PushRequestFactory;

$client = static::getContainer()->get(InMemoryCloudTasksClient::class);

$this->bus->dispatch(new SendWelcomeEmail($userId));

$tasks = $client->flush();          // the tasks created so far
self::assertCount(1, $tasks);

$response = static::$kernel->handle(PushRequestFactory::fromTask($tasks[0]));
self::assertSame(200, $response->getStatusCode());

// Simulate the third Cloud Tasks attempt:
static::$kernel->handle(PushRequestFactory::fromTask($tasks[0], retryCount: 2));
```

The in-memory client honours task names (a reused name throws, like Cloud Tasks). Like Symfony's
in-memory transport it is a `kernel.reset` service, so it is emptied when the kernel handles another
request or the test ends: read the tasks before handling the next request.

## Limitations

- **Push only.** Nothing is pulled: `messenger:consume` on this transport idles, and message counts
  (`messenger:stats`) are not available.
- **At-least-once.** Cloud Tasks may, rarely, deliver a task twice. Keep handlers idempotent.
- **No ordering** guarantee between messages.
- **Size and time limits** of Cloud Tasks: 1 MB per task, `scheduleTime` at most 30 days ahead,
  `dispatch_deadline` between 15 seconds and 30 minutes.
- **Deduplication window** is limited to about an hour after the original task ran or was deleted;
  named tasks are also slightly slower to create.
- **Worker lifecycle events** (`WorkerStartedEvent`, `WorkerRunningEvent`, `WorkerStoppedEvent`)
  never fire: there is no worker. Per-message events do.
- **Batch handlers** receive messages one at a time, there is no worker to accumulate a batch.
- The transport cannot create the queue (`messenger:setup-transports` does nothing); create it with
  `gcloud` or Terraform.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix
```

## License

MIT, see [LICENSE](LICENSE).
