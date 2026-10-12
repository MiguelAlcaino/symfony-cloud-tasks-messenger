<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Security;

interface IdTokenVerifierInterface
{
    /**
     * Verifies the signature, expiry, issuer and audience of a Google-signed OIDC ID token.
     *
     * @return array<string, mixed> the token claims
     *
     * @throws AuthenticationFailedException
     */
    public function verify(string $idToken, string $audience): array;
}
