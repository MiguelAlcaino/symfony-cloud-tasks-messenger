<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Transport;

use MiguelAlcaino\CloudTasksMessenger\Client\CloudTasksClientInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @implements TransportFactoryInterface<CloudTasksTransport>
 */
final class CloudTasksTransportFactory implements TransportFactoryInterface
{
    private readonly ClockInterface $clock;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CloudTasksClientInterface $client,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new CloudTasksTransport(
            Configuration::fromDsn($dsn, $options),
            $this->client,
            $serializer,
            $this->clock,
            $this->logger,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, Configuration::DSN_SCHEME.'://');
    }
}
