<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Transport;

use MiguelAlcaino\CloudTasksMessenger\Transport\Configuration;
use MiguelAlcaino\CloudTasksMessenger\Transport\RetryMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;

final class ConfigurationTest extends TestCase
{
    private const HANDLER_URL = 'https://app.example.com/_messenger/cloud-tasks';

    public function testFromDsnWithEveryOption(): void
    {
        $configuration = Configuration::fromDsn(
            'cloud-tasks://my-project/europe-west1/my-queue?handler_url='.urlencode(self::HANDLER_URL)
            .'&service_account=tasks@my-project.iam.gserviceaccount.com&audience=https://app.example.com'
            .'&auth_secret=s3cret&retry_mode=messenger&dispatch_deadline=600',
            ['transport_name' => 'async'],
        );

        self::assertSame('async', $configuration->transportName);
        self::assertSame('my-project', $configuration->projectId);
        self::assertSame('europe-west1', $configuration->location);
        self::assertSame('my-queue', $configuration->queue);
        self::assertSame(self::HANDLER_URL, $configuration->handlerUrl);
        self::assertSame('tasks@my-project.iam.gserviceaccount.com', $configuration->serviceAccount);
        self::assertSame('https://app.example.com', $configuration->audience);
        self::assertSame('s3cret', $configuration->authSecret);
        self::assertSame(RetryMode::Messenger, $configuration->retryMode);
        self::assertSame(600, $configuration->dispatchDeadline);
        self::assertSame('projects/my-project/locations/europe-west1/queues/my-queue', $configuration->getQueuePath());
    }

    public function testOptionsFillWhatTheDsnLeavesOutAndDefaultsApply(): void
    {
        $configuration = Configuration::fromDsn('cloud-tasks://my-project/europe-west1/my-queue', [
            'transport_name' => 'async',
            'handler_url' => self::HANDLER_URL,
            'service_account' => 'tasks@my-project.iam.gserviceaccount.com',
        ]);

        self::assertSame(self::HANDLER_URL, $configuration->audience, 'The audience defaults to the handler URL, like Cloud Tasks does.');
        self::assertNull($configuration->authSecret);
        self::assertSame(RetryMode::CloudTasks, $configuration->retryMode);
        self::assertNull($configuration->dispatchDeadline);
    }

    public function testDsnQueryTakesPrecedenceOverOptions(): void
    {
        $configuration = Configuration::fromDsn('cloud-tasks://p/l/q?auth_secret=from-dsn', [
            'transport_name' => 'async',
            'handler_url' => self::HANDLER_URL,
            'auth_secret' => 'from-options',
        ]);

        self::assertSame('from-dsn', $configuration->authSecret);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        $valid = ['transport_name' => 'async', 'handler_url' => self::HANDLER_URL, 'auth_secret' => 's'];

        yield 'wrong scheme' => ['sqs://p/l/q', $valid, 'must start with "cloud-tasks://"'];
        yield 'missing queue' => ['cloud-tasks://p/l', $valid, 'must contain the location and the queue'];
        yield 'too many path segments' => ['cloud-tasks://p/l/q/x', $valid, 'must contain the location and the queue'];
        yield 'missing handler url' => ['cloud-tasks://p/l/q', ['transport_name' => 'async', 'auth_secret' => 's'], 'needs the "handler_url" option'];
        yield 'relative handler url' => ['cloud-tasks://p/l/q', ['handler_url' => '/_messenger'] + $valid, 'absolute http(s) URL'];
        yield 'no authentication' => ['cloud-tasks://p/l/q', ['transport_name' => 'async', 'handler_url' => self::HANDLER_URL], 'needs "service_account"'];
        yield 'unknown option' => ['cloud-tasks://p/l/q?foo=bar', $valid, 'Unknown option(s) "foo"'];
        yield 'invalid retry mode' => ['cloud-tasks://p/l/q?retry_mode=never', $valid, 'Invalid "retry_mode"'];
        yield 'non integer deadline' => ['cloud-tasks://p/l/q?dispatch_deadline=1.5', $valid, 'integer number of seconds'];
        yield 'deadline out of range' => ['cloud-tasks://p/l/q?dispatch_deadline=10', $valid, 'between 15 and 1800'];
        yield 'invalid service account' => ['cloud-tasks://p/l/q?service_account=nope', $valid, 'service account email'];
        yield 'audience without service account' => ['cloud-tasks://p/l/q?audience=x', $valid, 'only makes sense together with "service_account"'];
        yield 'invalid queue id' => ['cloud-tasks://p/l/q.x', $valid, 'Invalid Cloud Tasks queue id'];
        yield 'missing transport name' => ['cloud-tasks://p/l/q', ['transport_name' => ''] + $valid, 'needs a transport name'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRejected(string $dsn, array $options, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Configuration::fromDsn($dsn, $options);
    }
}
