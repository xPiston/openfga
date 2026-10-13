<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Piston\OpenFga\Configuration\Credentials;
use Piston\OpenFga\Configuration\Endpoint;
use Piston\OpenFga\Configuration\NoCredentials;
use Piston\OpenFga\Configuration\StoreId;
use Piston\OpenFga\Http\Client;
use Piston\OpenFga\OpenFga;

/** Builds an SDK wired to a fake transport, so tests read as tests. */
final class Fga
{
    /** A store id that is a valid ULID, used wherever the value is irrelevant. */
    public const STORE = '01M4T8JQ0KTTDRR0CGQZ3R2RPE';

    public const MODEL = '01M4T8JQ1QC6Z7T15Z572NQ0YW';

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function with(
        FakeHttpClient $http,
        ?string $store = self::STORE,
        ?string $model = null,
        ?Credentials $credentials = null,
        string $url = 'http://openfga.test',
    ): OpenFga {
        return new OpenFga(self::client($http, $store, $credentials, $url), $model);
    }

    public static function client(
        FakeHttpClient $http,
        ?string $store = self::STORE,
        ?Credentials $credentials = null,
        string $url = 'http://openfga.test',
    ): Client {
        $factory = new Psr17Factory;

        return new Client(
            new Endpoint($url),
            $credentials ?? NoCredentials::create(),
            $http,
            $factory,
            $factory,
            $store === null ? null : StoreId::of($store),
        );
    }
}
