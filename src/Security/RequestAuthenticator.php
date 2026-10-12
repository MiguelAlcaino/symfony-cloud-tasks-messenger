<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Security;

use MiguelAlcaino\CloudTasksMessenger\Transport\CloudTasksTransport;
use MiguelAlcaino\CloudTasksMessenger\Transport\Configuration;
use Symfony\Component\HttpFoundation\Request;

/**
 * Checks that a push request comes from Cloud Tasks for the given transport. Every mechanism the
 * transport is configured with must pass: the OIDC token when "service_account" is set and the
 * shared secret header when "auth_secret" is set.
 */
final class RequestAuthenticator
{
    public function __construct(
        private readonly IdTokenVerifierInterface $idTokenVerifier,
    ) {
    }

    /**
     * @throws AuthenticationFailedException
     */
    public function authenticate(Request $request, Configuration $configuration): void
    {
        if (null === $configuration->serviceAccount && null === $configuration->authSecret) {
            throw new AuthenticationFailedException('The transport has no authentication configured.');
        }

        if (null !== $configuration->authSecret) {
            $secret = $request->headers->get(CloudTasksTransport::SECRET_HEADER);
            if (null === $secret || !hash_equals($configuration->authSecret, $secret)) {
                throw new AuthenticationFailedException('Missing or invalid shared secret header.');
            }
        }

        if (null !== $configuration->serviceAccount) {
            $this->authenticateOidc($request, $configuration->serviceAccount, (string) $configuration->audience);
        }
    }

    private function authenticateOidc(Request $request, string $serviceAccount, string $audience): void
    {
        $authorization = (string) $request->headers->get('Authorization');
        if (1 !== preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            throw new AuthenticationFailedException('Missing OIDC bearer token.');
        }

        $claims = $this->idTokenVerifier->verify($matches[1], $audience);

        if (($claims['email'] ?? null) !== $serviceAccount) {
            throw new AuthenticationFailedException('The OIDC token was issued to another service account.');
        }

        $verified = $claims['email_verified'] ?? false;
        if (true !== $verified && 'true' !== $verified) {
            throw new AuthenticationFailedException('The OIDC token email is not verified.');
        }
    }
}
