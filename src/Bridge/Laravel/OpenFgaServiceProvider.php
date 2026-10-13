<?php

declare(strict_types=1);

namespace Piston\OpenFga\Bridge\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Piston\OpenFga\Bridge\Laravel\Middleware\AuthorizeWithOpenFga;
use Piston\OpenFga\Configuration\ClientCredentials;
use Piston\OpenFga\Configuration\Credentials;
use Piston\OpenFga\Configuration\Endpoint;
use Piston\OpenFga\Configuration\HttpTokenIssuer;
use Piston\OpenFga\Configuration\NoCredentials;
use Piston\OpenFga\Configuration\PreSharedKey;
use Piston\OpenFga\Discovery;
use Piston\OpenFga\Exception\MissingHttpClient;
use Piston\OpenFga\OpenFga;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Wires OpenFGA into a Laravel application.
 *
 * Three bindings and one optional Gate hook. The hook is opt-out rather than
 * opt-in because it is harmless by default: it abstains for every object that
 * does not implement FgaObject, so installing this package changes the
 * behaviour of exactly nothing until a model asks it to.
 */
final class OpenFgaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/openfga.php', 'openfga');

        $this->app->singleton(OpenFga::class, function (Container $app): OpenFga {
            $config = $this->config($app);

            $url = $config['url'] ?? null;
            $store = $config['store'] ?? null;
            $model = $config['model'] ?? null;
            $max = $config['max_tuples_per_write'] ?? 100;

            $transport = self::optional($app, ClientInterface::class) ?? Discovery::client();
            $requests = self::optional($app, RequestFactoryInterface::class) ?? Discovery::requestFactory();
            $streams = self::optional($app, StreamFactoryInterface::class) ?? Discovery::streamFactory();

            if ($transport === null || $requests === null || $streams === null) {
                throw MissingHttpClient::create();
            }

            return OpenFga::create(
                endpoint: new Endpoint(is_string($url) && $url !== '' ? $url : Endpoint::DEFAULT_URL),
                credentials: $this->credentials($config, $transport, $requests, $streams),
                store: is_string($store) && $store !== '' ? $store : null,
                model: is_string($model) && $model !== '' ? $model : null,
                http: $transport,
                requests: $requests,
                streams: $streams,
                maxTuplesPerWrite: is_numeric($max) ? (int) $max : 100,
            );
        });

        $this->app->singleton(OpenFgaAuthorizer::class, function (Container $app): OpenFgaAuthorizer {
            $config = $this->config($app);
            $userType = $config['user_type'] ?? 'user';

            return new OpenFgaAuthorizer(
                $app->make(OpenFga::class),
                is_string($userType) && $userType !== '' ? $userType : 'user',
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/openfga.php' => $this->app->configPath('openfga.php'),
            ], 'openfga-config');
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('fga', AuthorizeWithOpenFga::class);

        if ($this->gateEnabled()) {
            // Abstains unless the object opted in - see OpenFgaAuthorizer.
            Gate::before(function (mixed $user, string $ability, array $arguments = []): ?bool {
                /** @var list<mixed> $arguments */
                $arguments = array_values($arguments);

                return $this->app->make(OpenFgaAuthorizer::class)->__invoke($user, $ability, $arguments);
            });
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [OpenFga::class, OpenFgaAuthorizer::class];
    }

    private function gateEnabled(): bool
    {
        $config = $this->config($this->app);
        $enabled = $config['gate'] ?? true;

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function credentials(
        array $config,
        ClientInterface $transport,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
    ): Credentials {
        $credentials = $config['credentials'] ?? [];

        /** @var array<string, mixed> $credentials */
        $credentials = is_array($credentials) ? $credentials : [];

        $method = $credentials['method'] ?? 'none';
        $method = is_string($method) ? strtolower(trim($method)) : 'none';

        return match ($method) {
            '', 'none' => NoCredentials::create(),
            'preshared', 'api_token', 'token' => PreSharedKey::of(
                self::required($credentials, 'token', 'OPENFGA_API_TOKEN'),
            ),
            'client_credentials', 'oidc' => $this->clientCredentials($credentials, $transport, $requests, $streams),
            default => throw new RuntimeException(sprintf(
                'openfga.credentials.method is "%s"; it has to be "none", "preshared" or "client_credentials".',
                $method,
            )),
        };
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function clientCredentials(
        array $credentials,
        ClientInterface $transport,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
    ): ClientCredentials {
        $settings = $credentials['client_credentials'] ?? [];

        /** @var array<string, mixed> $settings */
        $settings = is_array($settings) ? $settings : [];

        return new ClientCredentials(new HttpTokenIssuer(
            self::required($settings, 'issuer', 'OPENFGA_TOKEN_ISSUER'),
            self::required($settings, 'client_id', 'OPENFGA_CLIENT_ID'),
            self::required($settings, 'client_secret', 'OPENFGA_CLIENT_SECRET'),
            self::required($settings, 'audience', 'OPENFGA_API_AUDIENCE'),
            $transport,
            $requests,
            $streams,
        ));
    }

    /** @return array<string, mixed> */
    private function config(Container $app): array
    {
        $config = $app->make(Repository::class)->get('openfga', []);

        /** @var array<string, mixed> $config */
        return is_array($config) ? $config : [];
    }

    /** @param array<string, mixed> $config */
    private static function required(array $config, string $key, string $variable): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf(
                'OpenFGA is not configured: %s is missing. Publish the config with '
                .'`php artisan vendor:publish --tag=openfga-config` to see every option.',
                $variable,
            ));
        }

        return $value;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T|null
     */
    private static function optional(Container $app, string $abstract): ?object
    {
        return $app->bound($abstract) ? $app->make($abstract) : null;
    }
}
