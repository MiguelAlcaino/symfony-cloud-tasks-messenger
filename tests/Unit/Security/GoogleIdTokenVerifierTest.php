<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Security;

use Google\Auth\AccessToken;
use MiguelAlcaino\CloudTasksMessenger\Security\AuthenticationFailedException;
use MiguelAlcaino\CloudTasksMessenger\Security\GoogleIdTokenVerifier;
use PHPUnit\Framework\TestCase;

final class GoogleIdTokenVerifierTest extends TestCase
{
    private const AUDIENCE = 'https://app.example.com/_messenger/cloud-tasks';

    public function testVerificationErrorsBecomeAuthenticationFailures(): void
    {
        $accessToken = $this->createMock(AccessToken::class);
        $accessToken->method('verify')->willThrowException(new \UnexpectedValueException('Audience does not match'));

        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage('Invalid OIDC token: Audience does not match');

        (new GoogleIdTokenVerifier(null, $accessToken))->verify('token', self::AUDIENCE);
    }

    public function testFalseIsAnAuthenticationFailure(): void
    {
        $accessToken = $this->createMock(AccessToken::class);
        $accessToken->method('verify')->willReturn(false);

        $this->expectException(AuthenticationFailedException::class);

        (new GoogleIdTokenVerifier(null, $accessToken))->verify('token', self::AUDIENCE);
    }

    public function testRejectsTokensFromOtherIssuers(): void
    {
        $accessToken = $this->createMock(AccessToken::class);
        $accessToken->method('verify')->willReturn(['iss' => 'https://evil.example.com', 'email' => 'x@y.z']);

        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage('not issued by Google');

        (new GoogleIdTokenVerifier(null, $accessToken))->verify('token', self::AUDIENCE);
    }

    public function testReturnsTheClaimsOfAValidToken(): void
    {
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => self::AUDIENCE, 'email' => 'tasks@p.iam.gserviceaccount.com', 'email_verified' => true];
        $accessToken = $this->createMock(AccessToken::class);
        $accessToken->expects(self::once())->method('verify')
            ->with('token', ['audience' => self::AUDIENCE, 'throwException' => true])
            ->willReturn($claims);

        self::assertSame($claims, (new GoogleIdTokenVerifier(null, $accessToken))->verify('token', self::AUDIENCE));
    }
}
