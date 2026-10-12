<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Transport;

use Symfony\Component\Messenger\Exception\InvalidArgumentException;

/**
 * Immutable transport configuration, built from the DSN and the transport options.
 *
 * DSN shape: cloud-tasks://PROJECT/LOCATION/QUEUE?handler_url=...&service_account=...
 */
final class Configuration
{
    public const DSN_SCHEME = 'cloud-tasks';

    private const AVAILABLE_OPTIONS = [
        'handler_url',
        'service_account',
        'audience',
        'auth_secret',
        'retry_mode',
        'dispatch_deadline',
        'transport_name',
    ];

    private const NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';
    private const PROJECT_PATTERN = '/^[a-z0-9.:-]+$/';

    public readonly ?string $audience;

    public function __construct(
        public readonly string $transportName,
        public readonly string $projectId,
        public readonly string $location,
        public readonly string $queue,
        public readonly string $handlerUrl,
        public readonly ?string $serviceAccount = null,
        ?string $audience = null,
        public readonly ?string $authSecret = null,
        public readonly RetryMode $retryMode = RetryMode::CloudTasks,
        public readonly ?int $dispatchDeadline = null,
    ) {
        if ('' === $transportName) {
            throw new InvalidArgumentException('The Cloud Tasks transport needs a transport name.');
        }
        if (1 !== preg_match(self::PROJECT_PATTERN, $projectId)) {
            throw new InvalidArgumentException(\sprintf('Invalid Google Cloud project id "%s".', $projectId));
        }
        if (1 !== preg_match(self::NAME_PATTERN, $location)) {
            throw new InvalidArgumentException(\sprintf('Invalid Cloud Tasks location "%s".', $location));
        }
        if (1 !== preg_match(self::NAME_PATTERN, $queue)) {
            throw new InvalidArgumentException(\sprintf('Invalid Cloud Tasks queue id "%s".', $queue));
        }
        $scheme = parse_url($handlerUrl, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['http', 'https'], true) || null === parse_url($handlerUrl, \PHP_URL_HOST)) {
            throw new InvalidArgumentException(\sprintf('The "handler_url" option must be an absolute http(s) URL, "%s" given.', $handlerUrl));
        }
        if (null === $serviceAccount && null === $authSecret) {
            throw new InvalidArgumentException(\sprintf('The Cloud Tasks transport "%s" needs "service_account" (OIDC) and/or "auth_secret" so that the push endpoint can authenticate Cloud Tasks.', $transportName));
        }
        if (null !== $serviceAccount && false === filter_var($serviceAccount, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(\sprintf('The "service_account" option must be a service account email, "%s" given.', $serviceAccount));
        }
        if (null === $serviceAccount && null !== $audience) {
            throw new InvalidArgumentException('The "audience" option only makes sense together with "service_account".');
        }
        if (null !== $dispatchDeadline && ($dispatchDeadline < 15 || $dispatchDeadline > 1800)) {
            throw new InvalidArgumentException(\sprintf('The "dispatch_deadline" option must be between 15 and 1800 seconds, %d given.', $dispatchDeadline));
        }

        $this->audience = null === $serviceAccount ? null : ($audience ?? $handlerUrl);
    }

    /**
     * Options from the DSN query string take precedence over the transport "options".
     *
     * @param array<string, mixed> $options
     */
    public static function fromDsn(string $dsn, array $options = []): self
    {
        if (!str_starts_with($dsn, self::DSN_SCHEME.'://')) {
            throw new InvalidArgumentException(\sprintf('The Cloud Tasks transport DSN must start with "%s://".', self::DSN_SCHEME));
        }

        $parts = parse_url($dsn);
        if (false === $parts || !isset($parts['host'])) {
            throw new InvalidArgumentException('The Cloud Tasks transport DSN is invalid, expected "cloud-tasks://PROJECT/LOCATION/QUEUE?handler_url=...".');
        }

        $path = explode('/', trim($parts['path'] ?? '', '/'));
        if (2 !== \count($path) || '' === $path[0] || '' === $path[1]) {
            throw new InvalidArgumentException('The Cloud Tasks transport DSN must contain the location and the queue: "cloud-tasks://PROJECT/LOCATION/QUEUE".');
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $merged = [];
        foreach ($query + $options as $name => $value) {
            $merged[(string) $name] = $value;
        }
        $options = $merged;

        $unknown = array_diff(array_keys($options), self::AVAILABLE_OPTIONS);
        if ([] !== $unknown) {
            throw new InvalidArgumentException(\sprintf('Unknown option(s) "%s" for the Cloud Tasks transport. Available options: "%s".', implode('", "', $unknown), implode('", "', self::AVAILABLE_OPTIONS)));
        }

        $retryMode = self::stringOption($options, 'retry_mode') ?? RetryMode::CloudTasks->value;
        $retryModeEnum = RetryMode::tryFrom($retryMode);
        if (null === $retryModeEnum) {
            throw new InvalidArgumentException(\sprintf('Invalid "retry_mode" "%s", expected one of "cloud_tasks", "messenger".', $retryMode));
        }

        $dispatchDeadline = $options['dispatch_deadline'] ?? null;
        if (null !== $dispatchDeadline) {
            if (!is_numeric($dispatchDeadline) || (int) $dispatchDeadline != $dispatchDeadline) {
                throw new InvalidArgumentException('The "dispatch_deadline" option must be an integer number of seconds.');
            }
            $dispatchDeadline = (int) $dispatchDeadline;
        }

        $handlerUrl = self::stringOption($options, 'handler_url');
        if (null === $handlerUrl) {
            throw new InvalidArgumentException('The Cloud Tasks transport needs the "handler_url" option: the absolute URL of the push endpoint.');
        }

        return new self(
            transportName: self::stringOption($options, 'transport_name') ?? '',
            projectId: rawurldecode($parts['host']),
            location: rawurldecode($path[0]),
            queue: rawurldecode($path[1]),
            handlerUrl: $handlerUrl,
            serviceAccount: self::stringOption($options, 'service_account'),
            audience: self::stringOption($options, 'audience'),
            authSecret: self::stringOption($options, 'auth_secret'),
            retryMode: $retryModeEnum,
            dispatchDeadline: $dispatchDeadline,
        );
    }

    /**
     * Fully-qualified queue name: projects/PROJECT/locations/LOCATION/queues/QUEUE.
     */
    public function getQueuePath(): string
    {
        return \sprintf('projects/%s/locations/%s/queues/%s', $this->projectId, $this->location, $this->queue);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf('The "%s" option must be a string.', $name));
        }

        return $value;
    }
}
