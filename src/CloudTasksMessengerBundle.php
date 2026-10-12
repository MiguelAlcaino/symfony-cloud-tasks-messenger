<?php

declare(strict_types=1);

namespace MiguelAlcaino\CloudTasksMessenger;

use MiguelAlcaino\CloudTasksMessenger\Client\CloudTasksClientInterface;
use MiguelAlcaino\CloudTasksMessenger\Client\GoogleCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Client\InMemoryCloudTasksClient;
use MiguelAlcaino\CloudTasksMessenger\Controller\PushController;
use MiguelAlcaino\CloudTasksMessenger\Receiver\PushedMessageProcessor;
use MiguelAlcaino\CloudTasksMessenger\Security\GoogleIdTokenVerifier;
use MiguelAlcaino\CloudTasksMessenger\Security\IdTokenVerifierInterface;
use MiguelAlcaino\CloudTasksMessenger\Security\RequestAuthenticator;
use MiguelAlcaino\CloudTasksMessenger\Transport\CloudTasksTransportFactory;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class CloudTasksMessengerBundle extends AbstractBundle
{
    protected string $extensionAlias = 'cloud_tasks_messenger';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('client')
                    ->info('Service id of the CloudTasksClientInterface implementation. Use "cloud_tasks_messenger.client.in_memory" in tests.')
                    ->defaultValue('cloud_tasks_messenger.client.google')
                ->end()
                ->arrayNode('google_client_options')
                    ->info('Options for Google\Cloud\Tasks\V2\Client\CloudTasksClient (credentials, apiEndpoint...). Empty means Application Default Credentials.')
                    ->normalizeKeys(false)
                    ->variablePrototype()->end()
                ->end()
                ->scalarNode('id_token_verifier')
                    ->info('Service id of the IdTokenVerifierInterface implementation that checks the OIDC tokens sent by Cloud Tasks.')
                    ->defaultValue('cloud_tasks_messenger.id_token_verifier.google')
                ->end()
                ->scalarNode('certificates_cache')
                    ->info('PSR-6 cache pool service id for Google\'s public certificates, null to download them on every verification.')
                    ->defaultValue('cache.app')
                ->end()
            ->end();
    }

    /**
     * @param array{client: string, google_client_options: array<string, mixed>, id_token_verifier: string, certificates_cache: ?string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('cloud_tasks_messenger.client.google', GoogleCloudTasksClient::class)
            ->args([$config['google_client_options']]);

        $services->set('cloud_tasks_messenger.client.in_memory', InMemoryCloudTasksClient::class)
            ->public()
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->alias(CloudTasksClientInterface::class, $config['client']);
        $services->alias(InMemoryCloudTasksClient::class, 'cloud_tasks_messenger.client.in_memory')->public();

        $services->set('cloud_tasks_messenger.id_token_verifier.google', GoogleIdTokenVerifier::class)
            ->args([null === $config['certificates_cache'] ? null : service($config['certificates_cache'])->nullOnInvalid()]);

        $services->alias(IdTokenVerifierInterface::class, $config['id_token_verifier']);

        $services->set('cloud_tasks_messenger.request_authenticator', RequestAuthenticator::class)
            ->args([service(IdTokenVerifierInterface::class)]);

        $services->set('cloud_tasks_messenger.transport_factory', CloudTasksTransportFactory::class)
            ->args([
                service(CloudTasksClientInterface::class),
                service('clock')->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('messenger.transport_factory')
            ->tag('monolog.logger', ['channel' => 'messenger']);

        $services->set('cloud_tasks_messenger.pushed_message_processor', PushedMessageProcessor::class)
            ->args([
                service('messenger.receiver_locator'),
                service('messenger.routable_message_bus'),
                service('cloud_tasks_messenger.request_authenticator'),
                service('event_dispatcher')->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'messenger']);

        $services->set(PushController::class)
            ->args([service('cloud_tasks_messenger.pushed_message_processor')])
            ->public()
            ->tag('controller.service_arguments');
    }
}
