<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Security;

use Firebase\JWT\JWT;
use Google\Auth\AccessToken;
use GuzzleHttp\Psr7\Response;
use MiguelAlcaino\CloudTasksMessenger\Security\AuthenticationFailedException;
use MiguelAlcaino\CloudTasksMessenger\Security\GoogleIdTokenVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Runs google/auth's real RS256 check, which the other verifier tests mock away. It needs phpseclib v3, which
 * google/auth only suggests: without it every push was rejected with "Please require phpseclib/phpseclib v3".
 * Google's certificate endpoint is replaced by an HTTP handler serving the key set of a throwaway key.
 */
final class GoogleIdTokenVerifierSignatureTest extends TestCase
{
    private const AUDIENCE = 'https://app.example.com/_messenger/cloud-tasks';
    private const KEY_ID = 'test-key';

    private static \OpenSSLAsymmetricKey $privateKey;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::$privateKey = $key;
    }

    public function testRejectsATokenForAnotherAudience(): void
    {
        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage('Audience does not match');

        $this->verifier()->verify($this->token(['aud' => 'https://other.example.com']), self::AUDIENCE);
    }

    public function testRejectsATokenSignedWithAnotherKey(): void
    {
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($otherKey);
        $token = JWT::encode($this->claims([]), $otherKey, 'RS256', self::KEY_ID);

        $this->expectException(AuthenticationFailedException::class);

        $this->verifier()->verify($token, self::AUDIENCE);
    }

    public function testAcceptsAGoogleSignedTokenForTheAudience(): void
    {
        $claims = $this->verifier()->verify($this->token([]), self::AUDIENCE);

        self::assertSame('invoker@project.iam.gserviceaccount.com', $claims['email']);
        self::assertTrue($claims['email_verified']);
    }

    private function verifier(): GoogleIdTokenVerifier
    {
        $details = openssl_pkey_get_details(self::$privateKey);
        self::assertIsArray($details);
        $keySet = ['keys' => [[
            'kid' => self::KEY_ID,
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ]]];

        $httpHandler = static fn (): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($keySet, \JSON_THROW_ON_ERROR));

        return new GoogleIdTokenVerifier(null, new AccessToken($httpHandler));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides): string
    {
        return JWT::encode($this->claims($overrides), self::$privateKey, 'RS256', self::KEY_ID);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::AUDIENCE,
            'email' => 'invoker@project.iam.gserviceaccount.com',
            'email_verified' => true,
            'iat' => time(),
            'exp' => time() + 300,
        ], $overrides);
    }
}
