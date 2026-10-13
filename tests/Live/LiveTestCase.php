<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Live;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Api\Store;
use Piston\OpenFga\Configuration\Endpoint;
use Piston\OpenFga\Configuration\NoCredentials;
use Piston\OpenFga\Http\Client;
use Piston\OpenFga\OpenFga;
use Symfony\Component\HttpClient\Psr18Client;
use Throwable;

/**
 * A test against a real OpenFGA server.
 *
 * OpenFGA is open source and starts in one command, so unlike a hosted
 * platform there is no excuse for a PHP SDK that has never spoken to it:
 *
 *     docker run -d -p 8080:8080 openfga/openfga run
 *     vendor/bin/phpunit -c phpunit.live.xml
 *
 * Each test gets its own store and deletes it afterwards, so the suite leaves
 * nothing behind and several can run against one server.
 */
abstract class LiveTestCase extends TestCase
{
    protected OpenFga $fga;

    private ?Store $store = null;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('OPENFGA_URL') ?: Endpoint::DEFAULT_URL;

        $factory = new Psr17Factory;
        $client = new Client(
            new Endpoint($url),
            NoCredentials::create(),
            new Psr18Client,
            $factory,
            $factory,
        );

        $root = new OpenFga($client);

        try {
            $this->store = $root->stores()->create('live-'.bin2hex(random_bytes(5)));
        } catch (Throwable $e) {
            self::markTestSkipped(sprintf(
                'No OpenFGA at %s (%s). Start one with: docker run -d -p 8080:8080 openfga/openfga run',
                $url,
                $e->getMessage(),
            ));
        }

        $this->fga = $root->withStore($this->store->id);
    }

    protected function tearDown(): void
    {
        if ($this->store !== null) {
            try {
                $this->fga->stores()->delete($this->store->id);
            } catch (Throwable) {
                // A store that could not be deleted is not a test failure;
                // the names are unique, so nothing collides.
            }

            $this->store = null;
        }

        parent::tearDown();
    }

    /** Deploy a model from DSL and pin it. */
    protected function deploy(string $dsl): string
    {
        $id = $this->fga->models()->write($dsl);
        $this->fga = $this->fga->withModel($id);

        return $id;
    }

    protected function store(): Store
    {
        self::assertNotNull($this->store);

        return $this->store;
    }
}
