<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Security;

use MiguelAlcaino\CloudTasksMessenger\Security\AuthenticationFailedException;
use MiguelAlcaino\CloudTasksMessenger\Security\IdTokenVerifierInterface;
use MiguelAlcaino\CloudTasksMessenger\Security\RequestAuthenticator;
use MiguelAlcaino\CloudTasksMessenger\Transport\CloudTasksTransport;
use MiguelAlcaino\CloudTasksMessenger\Transport\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestAuthenticatorTest extends TestCase
{
    private const SERVICE_ACCOUNT = 'tasks@my-project.iam.gserviceaccount.com';
    private const AUDIENCE = 'https://app.example.com/_messenger/cloud-tasks';

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidSecrets(): iterable
    {
        yield 'missing' => [[], 'Missing or invalid shared secret header.'];
        yield 'wrong' => [[CloudTasksTransport::SECRET_HEADER => 'guess'], 'Missing or invalid shared secret header.'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('invalidSecrets')]
    public function testRejectsAMissingOrWrongSecret(array $headers, string $expectedMessage): void
    {
        $verifier = $this->createMock(IdTokenVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new RequestAuthenticator($verifier))->authenticate($this->request($headers), $this->configuration(secret: 's3cret'));
    }

    public function testRejectsAMissingBearerToken(): void
    {
        $verifier = $this->createMock(IdTokenVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage('Missing OIDC bearer token.');

        (new RequestAuthenticator($verifier))->authenticate($this->request(['Authorization' => 'Basic abc']), $this->configuration(serviceAccount: self::SERVICE_ACCOUNT));
    }

    public function testRejectsATokenTheVerifierRefuses(): void
    {
        $verifier = $this->createMock(IdTokenVerifierInterface::class);
        $verifier->method('verify')->willThrowException(new AuthenticationFailedException('Invalid OIDC token: Expired token'));

        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage('Expired token');

        (new RequestAuthenticator($verifier))->authenticate($this->request(['Authorization' => 'Bearer token']), $this->configuration(serviceAccount: self::SERVICE_ACCOUNT));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidClaims(): iterable
    {
        yield 'other service account' => [['email' => 'intruder@other.iam.gserviceaccount.com', 'email_verified' => true], 'issued to another service account'];
        yield 'no email' => [['sub' => '123'], 'issued to another service account'];
        yield 'unverified email' => [['email' => self::SERVICE_ACCOUNT, 'email_verified' => false], 'email is not verified'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('invalidClaims')]
    public function testRejectsTokensWithUnexpectedClaims(array $claims, string $expectedMessage): void
    {
        $verifier = $this->createMock(IdTokenVerifierInterface::class);
        $verifier->method('verify')->willReturn($claims);

        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new RequestAuthenticator($verifier))->authenticate($this->request(['Authorization' => 'Bearer token']), $this->configuration(serviceAccount: self::SERVICE_ACCOUNT));
    }

    public function testRequiresBothMechanismsWhenBothAreConfigured(): void
    {
        $verifier = $this->createMock(IdTokenVerifierInterface::class);
        $verifier->method('verify')->willReturn(['email' => self::SERVICE_ACCOUNT, 'email_verified' => true]);

        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage('Missing or invalid shared secret header.');

        (new RequestAuthenticator($verifier))->authenticate(
            $this->request(['Authorization' => 'Bearer token']),
            $this->configuration(serviceAccount: self::SERVICE_ACCOUNT, secret: 's3cret'),
        );
    }

    public function testAcceptsAValidSecret(): void
    {
        $verifier = $this->createMock(IdTokenVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        (new RequestAuthenticator($verifier))->authenticate($this->request([CloudTasksTransport::SECRET_HEADER => 's3cret']), $this->configuration(secret: 's3cret'));

        $this->addToAssertionCount(1);
    }

    public function testAcceptsAValidTokenForTheConfiguredServiceAccountAndAudience(): void
    {
        $verifier = $this->createMock(IdTokenVerifierInterface::class);
        $verifier->expects(self::once())->method('verify')->with('the-token', self::AUDIENCE)
            ->willReturn(['email' => self::SERVICE_ACCOUNT, 'email_verified' => true]);

        (new RequestAuthenticator($verifier))->authenticate(
            $this->request(['Authorization' => 'Bearer the-token']),
            $this->configuration(serviceAccount: self::SERVICE_ACCOUNT),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): Request
    {
        $request = Request::create('https://app.example.com/_messenger/cloud-tasks', 'POST');
        $request->headers->add($headers);

        return $request;
    }

    private function configuration(?string $serviceAccount = null, ?string $secret = null): Configuration
    {
        return new Configuration('async', 'p', 'l', 'q', self::AUDIENCE, $serviceAccount, null, $secret);
    }
}
