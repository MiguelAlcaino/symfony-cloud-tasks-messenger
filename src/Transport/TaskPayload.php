<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Transport;

use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

/**
 * The JSON body of the HTTP task: the transport name plus the envelope encoded by the transport's
 * serializer. The serialized body is base64-encoded because serializers such as PhpSerializer may
 * produce bytes that are not valid UTF-8.
 */
final class TaskPayload
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $transportName,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    /**
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope
     */
    public static function fromEncodedEnvelope(string $transportName, array $encodedEnvelope): self
    {
        return new self($transportName, $encodedEnvelope['body'], $encodedEnvelope['headers'] ?? []);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MessageDecodingFailedException('The Cloud Tasks request body is not valid JSON.', 0, $e);
        }

        if (!\is_array($data) || !\is_string($data['transport'] ?? null) || !\is_string($data['body'] ?? null) || !\is_array($data['headers'] ?? [])) {
            throw new MessageDecodingFailedException('The Cloud Tasks request body is not a Messenger task payload.');
        }

        $body = base64_decode($data['body'], true);
        if (false === $body) {
            throw new MessageDecodingFailedException('The Cloud Tasks message body is not valid base64.');
        }

        $headers = [];
        foreach ($data['headers'] ?? [] as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                throw new MessageDecodingFailedException('The Cloud Tasks message headers must be a map of strings.');
            }
            $headers[$name] = $value;
        }

        return new self($data['transport'], $body, $headers);
    }

    public function toJson(): string
    {
        return json_encode([
            'transport' => $this->transportName,
            'headers' => (object) $this->headers,
            'body' => base64_encode($this->body),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    public function toEncodedEnvelope(): array
    {
        return ['body' => $this->body, 'headers' => $this->headers];
    }
}
