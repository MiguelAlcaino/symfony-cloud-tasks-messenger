<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Transport;

use MiguelAlcaino\CloudTasksMessenger\Transport\TaskPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

final class TaskPayloadTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'not json' => ['nope'];
        yield 'json scalar' => ['42'];
        yield 'missing transport' => ['{"body":"","headers":{}}'];
        yield 'missing body' => ['{"transport":"async"}'];
        yield 'body not base64' => ['{"transport":"async","body":"%%%"}'];
        yield 'headers not strings' => ['{"transport":"async","body":"","headers":{"a":1}}'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAreRejected(string $json): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        TaskPayload::fromJson($json);
    }

    public function testRoundTripKeepsBinaryBodiesAndHeaders(): void
    {
        $payload = TaskPayload::fromEncodedEnvelope('async', ['body' => "O:8:\"stdClass\"\0\xff", 'headers' => ['type' => 'App\Message']]);

        $decoded = TaskPayload::fromJson($payload->toJson());

        self::assertSame('async', $decoded->transportName);
        self::assertSame(['body' => "O:8:\"stdClass\"\0\xff", 'headers' => ['type' => 'App\Message']], $decoded->toEncodedEnvelope());
    }
}
