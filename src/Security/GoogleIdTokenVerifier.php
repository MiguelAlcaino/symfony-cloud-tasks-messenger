<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Security;

use Google\Auth\AccessToken;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Verifies Google-signed ID tokens against Google's public certificates. Pass a shared PSR-6 cache
 * (e.g. cache.app) so the certificates are not downloaded on every request.
 */
final class GoogleIdTokenVerifier implements IdTokenVerifierInterface
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    private readonly AccessToken $accessToken;

    public function __construct(?CacheItemPoolInterface $certificatesCache = null, ?AccessToken $accessToken = null)
    {
        $this->accessToken = $accessToken ?? new AccessToken(null, $certificatesCache);
    }

    public function verify(string $idToken, string $audience): array
    {
        try {
            $claims = $this->accessToken->verify($idToken, [
                'audience' => $audience,
                'throwException' => true,
            ]);
        } catch (\Throwable $e) {
            throw new AuthenticationFailedException('Invalid OIDC token: '.$e->getMessage(), 0, $e);
        }

        if (!\is_array($claims)) {
            throw new AuthenticationFailedException('Invalid OIDC token.');
        }

        if (!\in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new AuthenticationFailedException('The OIDC token was not issued by Google.');
        }

        $result = [];
        foreach ($claims as $name => $value) {
            $result[(string) $name] = $value;
        }

        return $result;
    }
}
