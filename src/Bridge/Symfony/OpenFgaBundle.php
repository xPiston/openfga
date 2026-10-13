<?php

declare(strict_types=1);

namespace Piston\OpenFga\Bridge\Symfony;

use Piston\OpenFga\Configuration\ClientCredentials;
use Piston\OpenFga\Configuration\Credentials;
use Piston\OpenFga\Configuration\Endpoint;
use Piston\OpenFga\Configuration\HttpTokenIssuer;
use Piston\OpenFga\Configuration\NoCredentials;
use Piston\OpenFga\Configuration\PreSharedKey;
use Piston\OpenFga\OpenFga;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Wires OpenFGA into a Symfony application.
 *
 * ```yaml
 * # config/packages/openfga.yaml
 * openfga:
 *     url: '%env(OPENFGA_URL)%'
 *     store: '%env(OPENFGA_STORE_ID)%'
 *     model: '%env(OPENFGA_MODEL_ID)%'
 * ```
 *
 * The voter is registered only when `voter: true` (the default) *and* the
 * Security component is installed, because a voter service in an application
 * without a firewall is a container error rather than a feature.
 */
final class OpenFgaBundle extends AbstractBundle
{
    protected string $extensionAlias = 'openfga';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('url')->defaultValue(Endpoint::DEFAULT_URL)
            ->info('Where the OpenFGA server is. The default is what `docker run openfga/openfga run` listens on.')->end()
            ->scalarNode('store')->defaultNull()
            ->info('The store id - a ULID, not the store name.')->end()
            ->scalarNode('model')->defaultNull()
            ->info('The authorization model id. Pin it: without one, every check is answered by whichever model is newest.')->end()
            ->scalarNode('user_type')->defaultValue('user')
            ->info('The authenticated user becomes "<user_type>:<getUserIdentifier()>", unless the user implements FgaUser.')->end()
            ->booleanNode('voter')->defaultTrue()
            ->info('Register a Security voter so #[IsGranted(\'viewer\', subject: \'document\')] asks OpenFGA.')->end()
            ->integerNode('max_tuples_per_write')->defaultValue(100)->min(1)
            ->info('The server\'s --max-tuples-per-write. A larger write is refused, not split: writes are transactional.')->end()
            ->arrayNode('credentials')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('method')->values(['none', 'preshared', 'client_credentials'])->defaultValue('none')
            ->info('"none" for a plain server, "preshared" for --authn-method=preshared, "client_credentials" for a managed one.')->end()
            ->scalarNode('token')->defaultNull()->info('The pre-shared key, without a "Bearer " prefix.')->end()
            ->scalarNode('issuer')->defaultNull()->end()
            ->scalarNode('client_id')->defaultNull()->end()
            ->scalarNode('client_secret')->defaultNull()->end()
            ->scalarNode('audience')->defaultNull()->end()
            ->end()
            ->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /**
         * @var array{
         *     url: string, store: ?string, model: ?string, user_type: string, voter: bool,
         *     max_tuples_per_write: int,
         *     credentials: array{method: string, token: ?string, issuer: ?string, client_id: ?string, client_secret: ?string, audience: ?string}
         * } $config
         */
        $services = $container->services();

        $services->set(Endpoint::class)->args([$config['url']]);

        $this->defineCredentials($services, $config['credentials']);

        $services->set(OpenFga::class)
            ->factory([OpenFga::class, 'create'])
            ->args([
                service(Endpoint::class),
                service(Credentials::class),
                $config['store'],
                $config['model'],
                service(ClientInterface::class)->nullOnInvalid(),
                service(RequestFactoryInterface::class)->nullOnInvalid(),
                service(StreamFactoryInterface::class)->nullOnInvalid(),
                $config['max_tuples_per_write'],
            ]);

        if ($config['voter'] && interface_exists(VoterInterface::class)) {
            $services->set(OpenFgaVoter::class)
                ->args([service(OpenFga::class), $config['user_type']])
                ->tag('security.voter');
        }
    }

    /**
     * Register the credentials service for the configured method.
     *
     * One method per shape rather than a generic factory: `NoCredentials` and
     * `PreSharedKey` are built by static factories, while `ClientCredentials`
     * is a plain constructor taking a service. Symfony's `factory()` cannot
     * express the third - `__construct` is not a callable factory method - and
     * a container that only fails at compile time is a container that fails in
     * somebody else's deployment.
     *
     * @param  \Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator  $services
     * @param  array{method: string, token: ?string, issuer: ?string, client_id: ?string, client_secret: ?string, audience: ?string}  $credentials
     */
    private function defineCredentials(object $services, array $credentials): void
    {
        $method = $credentials['method'];

        if ($method === 'preshared') {
            $services->set(Credentials::class, PreSharedKey::class)
                ->factory([PreSharedKey::class, 'of'])
                ->args([$credentials['token']]);

            return;
        }

        if ($method === 'client_credentials') {
            $services->set(Credentials::class, ClientCredentials::class)
                ->args([
                    inline_service(HttpTokenIssuer::class)->args([
                        $credentials['issuer'],
                        $credentials['client_id'],
                        $credentials['client_secret'],
                        $credentials['audience'],
                        service(ClientInterface::class),
                        service(RequestFactoryInterface::class),
                        service(StreamFactoryInterface::class),
                    ]),
                ]);

            return;
        }

        $services->set(Credentials::class, NoCredentials::class)
            ->factory([NoCredentials::class, 'create']);
    }
}
