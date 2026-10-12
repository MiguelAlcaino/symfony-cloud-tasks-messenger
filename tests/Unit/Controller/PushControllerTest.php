<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Unit\Controller;

use MiguelAlcaino\CloudTasksMessenger\Controller\PushController;
use MiguelAlcaino\CloudTasksMessenger\Receiver\PushedMessageProcessor;
use MiguelAlcaino\CloudTasksMessenger\Security\IdTokenVerifierInterface;
use MiguelAlcaino\CloudTasksMessenger\Security\RequestAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

final class PushControllerTest extends TestCase
{
    public function testAnswersWithTheResultStatusAndCode(): void
    {
        $processor = new PushedMessageProcessor(
            new ServiceLocator([]),
            $this->createMock(MessageBusInterface::class),
            new RequestAuthenticator($this->createMock(IdTokenVerifierInterface::class)),
        );

        $response = (new PushController($processor))(Request::create('/_messenger/cloud-tasks', 'POST', [], [], [], [], '{"transport":"nope","body":"","headers":{}}'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"status":"unknown_transport"}', $response->getContent());
    }
}
