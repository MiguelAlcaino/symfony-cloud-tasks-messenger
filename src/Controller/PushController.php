<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Controller;

use MiguelAlcaino\CloudTasksMessenger\Receiver\PushedMessageProcessor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The endpoint Cloud Tasks pushes messages to. Import config/routes.php to expose it.
 */
final class PushController
{
    public function __construct(
        private readonly PushedMessageProcessor $processor,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $result = $this->processor->process($request);

        return new JsonResponse(['status' => $result->value], $result->getStatusCode());
    }
}
