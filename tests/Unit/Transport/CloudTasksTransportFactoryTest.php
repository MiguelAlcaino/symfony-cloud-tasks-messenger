<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Transport;

use MiguelAlcaino\CloudTasksMessenger\Client\InMemoryCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Transport\CloudTasksTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class CloudTasksTransportFactoryTest extends TestCase
{
    public function testSupportsOnlyTheCloudTasksScheme(): void
    {
        $factory = new CloudTasksTransportFactory(new InMemoryCloudTasksClient());

        self::assertTrue($factory->supports('cloud-tasks://p/l/q', []));
        self::assertFalse($factory->supports('sync://', []));
        self::assertFalse($factory->supports('gps://default', []));
    }

    public function testCreatesATransportConfiguredFromTheDsnAndOptions(): void
    {
        $factory = new CloudTasksTransportFactory(new InMemoryCloudTasksClient());

        $transport = $factory->createTransport(
            'cloud-tasks://my-project/europe-west1/my-queue?auth_secret=s3cret',
            ['transport_name' => 'async', 'handler_url' => 'https://app.example.com/_messenger/cloud-tasks'],
            new PhpSerializer(),
        );

        self::assertSame('async', $transport->getConfiguration()->transportName);
        self::assertSame('projects/my-project/locations/europe-west1/queues/my-queue', $transport->getConfiguration()->getQueuePath());
    }
}
