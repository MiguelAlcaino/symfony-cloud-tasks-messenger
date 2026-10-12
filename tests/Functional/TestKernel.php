<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger\Tests\Functional;

use MiguelAlcaino\CloudTasksMessenger\CloudTasksMessengerBundle;
use MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures\TestMessage;
use MiguelAlcaino\CloudTasksMessenger\Tests\Fixtures\TestMessageHandler;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const HANDLER_URL = 'https://app.example.com/_messenger/cloud-tasks';
    public const SECRET = 's3cret';

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new CloudTasksMessengerBundle();
    }

    /**
     * Outside the repository: Symfony 7.4+ dumps config/reference.php into the project directory.
     */
    public function getProjectDir(): string
    {
        return sys_get_temp_dir().'/cloud-tasks-messenger-tests/'.md5(__FILE__);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/cache';
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => [
                    'async' => [
                        'dsn' => 'cloud-tasks://my-project/europe-west1/my-queue',
                        'options' => ['handler_url' => self::HANDLER_URL, 'auth_secret' => self::SECRET],
                        'retry_strategy' => ['max_retries' => 2, 'delay' => 1000],
                    ],
                    'async_messenger_retry' => [
                        'dsn' => 'cloud-tasks://my-project/europe-west1/my-queue?retry_mode=messenger',
                        'options' => ['handler_url' => self::HANDLER_URL, 'auth_secret' => self::SECRET],
                        'retry_strategy' => ['max_retries' => 2, 'delay' => 1000],
                    ],
                    'failed' => 'in-memory://',
                ],
                'routing' => [TestMessage::class => 'async'],
            ],
        ]);

        $container->extension('cloud_tasks_messenger', [
            'client' => 'cloud_tasks_messenger.client.in_memory',
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services
            ->set(TestMessageHandler::class)
            ->public()
            ->tag('messenger.message_handler');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@CloudTasksMessengerBundle/config/routes.php');
    }
}
