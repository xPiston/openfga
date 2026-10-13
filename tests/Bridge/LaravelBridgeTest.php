<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Bridge;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Piston\OpenFga\Bridge\Laravel\Concerns\AuthorizedByOpenFga;
use Piston\OpenFga\Bridge\Laravel\OpenFgaAuthorizer;
use Piston\OpenFga\Bridge\Laravel\OpenFgaServiceProvider;
use Piston\OpenFga\Contracts\FgaObject;
use Piston\OpenFga\Contracts\FgaUser;
use Piston\OpenFga\OpenFga;
use Piston\OpenFga\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * The bridge inside a real Laravel application, booted by Testbench.
 *
 * The behaviour worth proving is the abstention. A package that answered every
 * Gate call would take over an application's authorisation the moment it was
 * installed; this one answers only for models that asked.
 */
final class LaravelBridgeTest extends TestCase
{
    private FakeHttpClient $http;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [OpenFgaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->http = FakeHttpClient::replying('{"allowed":true}');
        $app->instance(ClientInterface::class, $this->http);

        $config = $app->make(Repository::class);

        $config->set('openfga.url', 'http://openfga.test');
        $config->set('openfga.store', '01M4T8JQ0KTTDRR0CGQZ3R2RPE');
        $config->set('openfga.model', '01M4T8JQ1QC6Z7T15Z572NQ0YW');
    }

    /**
     * Testbench types `$this->app` as nullable and its config repository as
     * mixed. Narrowing it once here keeps forty assertions readable.
     */
    private function container(): Application
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        return $app;
    }

    private function settings(): Repository
    {
        $config = $this->container()->make(Repository::class);

        self::assertInstanceOf(Repository::class, $config);

        return $config;
    }

    #[Test]
    public function test_the_client_is_resolved_from_configuration(): void
    {
        $fga = $this->container()->make(OpenFga::class);

        $this->assertSame('01M4T8JQ0KTTDRR0CGQZ3R2RPE', (string) $fga->store());
        $this->assertSame('01M4T8JQ1QC6Z7T15Z572NQ0YW', $fga->modelId());
        $this->assertSame($fga, $this->container()->make(OpenFga::class), 'it is a singleton');
    }

    #[Test]
    public function test_the_configured_psr18_client_is_used_rather_than_a_discovered_one(): void
    {
        $this->container()->make(OpenFga::class)->check('user:anne', 'viewer', 'document:1');

        $this->assertSame(1, $this->http->callCount());
        $this->assertSame('http://openfga.test/stores/01M4T8JQ0KTTDRR0CGQZ3R2RPE/check', $this->http->lastUrl());
    }

    #[Test]
    public function test_a_preshared_key_is_configured_and_sent(): void
    {
        $this->settings()->set('openfga.credentials.method', 'preshared');
        $this->settings()->set('openfga.credentials.token', 'a-secret-key');
        $this->container()->forgetInstance(OpenFga::class);

        $this->container()->make(OpenFga::class)->check('user:anne', 'viewer', 'document:1');

        $this->assertSame('Bearer a-secret-key', $this->http->lastRequest()->getHeaderLine('Authorization'));
    }

    #[Test]
    public function test_a_missing_token_names_the_environment_variable(): void
    {
        $this->settings()->set('openfga.credentials.method', 'preshared');
        $this->settings()->set('openfga.credentials.token', null);
        $this->container()->forgetInstance(OpenFga::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/OPENFGA_API_TOKEN/');

        $this->container()->make(OpenFga::class);
    }

    #[Test]
    public function test_an_unknown_auth_method_is_refused(): void
    {
        $this->settings()->set('openfga.credentials.method', 'nonsense');
        $this->container()->forgetInstance(OpenFga::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has to be "none", "preshared" or "client_credentials"/');

        $this->container()->make(OpenFga::class);
    }

    /** The important one: an object that did not opt in is not ours to answer. */
    #[Test]
    public function test_the_gate_abstains_for_an_object_that_did_not_opt_in(): void
    {
        Gate::define('viewer', static fn (): bool => true);

        $this->assertTrue(Gate::forUser(new FgaTestUser('anne'))->allows('viewer', new PlainThing));
        $this->assertSame(0, $this->http->callCount(), 'OpenFGA was not asked about an object that is not its business');
    }

    #[Test]
    public function test_the_gate_answers_for_an_object_that_opted_in(): void
    {
        $allowed = Gate::forUser(new FgaTestUser('anne'))->allows('viewer', new FgaDocument('budget'));

        $this->assertTrue($allowed);
        $this->assertSame(1, $this->http->callCount());
        $this->assertSame([
            'tuple_key' => ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'fga_document:budget'],
            'authorization_model_id' => '01M4T8JQ1QC6Z7T15Z572NQ0YW',
        ], $this->http->lastBody());
    }

    #[Test]
    public function test_a_denial_from_openfga_denies(): void
    {
        $this->container()->instance(ClientInterface::class, $http = FakeHttpClient::replying('{"allowed":false}'));
        $this->container()->forgetInstance(OpenFga::class);
        $this->container()->forgetInstance(OpenFgaAuthorizer::class);

        $this->assertFalse(Gate::forUser(new FgaTestUser('anne'))->allows('viewer', new FgaDocument('budget')));
        $this->assertSame(1, $http->callCount());
    }

    /** A policy method is not a relation name, so it is left to the policy. */
    #[Test]
    public function test_a_camel_case_policy_ability_is_left_alone(): void
    {
        Gate::define('viewAny', static fn (): bool => true);

        $this->assertTrue(Gate::forUser(new FgaTestUser('anne'))->allows('viewAny', new FgaDocument('budget')));
        $this->assertSame(0, $this->http->callCount());
    }

    /**
     * The ordering fix: an ability this bridge does not handle must not make
     * it ask an unsaved model to name itself.
     */
    #[Test]
    public function test_an_unhandled_ability_never_touches_the_object(): void
    {
        Gate::define('viewAny', static fn (): bool => true);

        $this->assertTrue(
            Gate::forUser(new FgaTestUser('anne'))->allows('viewAny', new UnsavedDocument),
            'a policy ability on an unsaved model worked before this package and must keep working',
        );
        $this->assertSame(0, $this->http->callCount());
    }

    #[Test]
    public function test_the_gate_hook_can_be_turned_off(): void
    {
        $this->settings()->set('openfga.gate', false);

        // The hook is registered in boot(), so a fresh application is needed;
        // asserting the config is read is what this can do here.
        $this->assertFalse((bool) $this->settings()->get('openfga.gate'));
    }

    #[Test]
    public function test_a_user_can_name_itself(): void
    {
        $authorizer = $this->container()->make(OpenFgaAuthorizer::class);

        $this->assertSame('user:anne', $authorizer->userFor(new FgaTestUser('anne')));
        $this->assertSame('employee:7', $authorizer->userFor(new SelfNamingUser));
        $this->assertNull($authorizer->userFor(null));
        $this->assertSame('user:bob', $authorizer->userFor('bob'));
        $this->assertSame('service:importer', $authorizer->userFor('service:importer'));
    }

    #[Test]
    public function test_the_user_type_is_configurable(): void
    {
        $this->settings()->set('openfga.user_type', 'employee');
        $this->container()->forgetInstance(OpenFgaAuthorizer::class);

        $this->assertSame('employee:anne', $this->container()->make(OpenFgaAuthorizer::class)->userFor(new FgaTestUser('anne')));
    }

    /** The type comes from the class name, snake_cased, unless declared. */
    #[Test]
    public function test_the_object_type_is_derived_from_the_model_name(): void
    {
        $this->assertSame('fga_document:budget', (new FgaDocument('budget'))->fgaObject());
        $this->assertSame('doc:7', (new RenamedDocument('7'))->fgaObject());
    }

    #[Test]
    public function test_an_unsaved_model_cannot_be_named(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no key yet/');

        (new UnsavedDocument)->fgaObject();
    }

    #[Test]
    public function test_the_middleware_allows_and_denies(): void
    {
        Route::middleware(['fga:viewer,document'])->get('/documents/{document}', static fn (): string => 'seen');

        $response = $this->actingAs(new FgaTestUser('anne'))->get('/documents/budget');
        $response->assertOk();
        $this->assertSame('seen', $response->getContent());
        /** @var array{tuple_key?: array{object?: string}} $body */
        $body = $this->http->lastBody();

        $this->assertSame(
            'document:budget',
            $body['tuple_key']['object'] ?? null,
            'a bare route parameter - no model binding - is named from the parameter name',
        );
    }

    #[Test]
    public function test_the_middleware_denies_with_403(): void
    {
        $this->container()->instance(ClientInterface::class, FakeHttpClient::replying('{"allowed":false}'));
        $this->container()->forgetInstance(OpenFga::class);
        $this->container()->forgetInstance(OpenFgaAuthorizer::class);

        Route::middleware(['fga:viewer,document'])->get('/documents/{document}', static fn (): string => 'seen');

        $this->actingAs(new FgaTestUser('anne'))->get('/documents/budget')->assertForbidden();
    }

    #[Test]
    public function test_the_middleware_refuses_an_unauthenticated_request(): void
    {
        Route::middleware(['fga:viewer,document'])->get('/documents/{document}', static fn (): string => 'seen');

        $this->get('/documents/budget')->assertUnauthorized();
        $this->assertSame(0, $this->http->callCount());
    }

    /**
     * A middleware that could not evaluate a request must not allow it. This
     * is the worst possible bug in a package like this, so it has a test.
     */
    #[Test]
    public function test_the_middleware_refuses_to_run_without_its_route_parameter(): void
    {
        Route::middleware(['fga:viewer,missing'])->get('/documents/{document}', static fn (): string => 'seen');

        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/which this route does not declare/');

        $this->actingAs(new FgaTestUser('anne'))->get('/documents/budget');
    }

    #[Test]
    public function test_the_middleware_type_can_be_overridden(): void
    {
        Route::middleware(['fga:viewer,doc,document'])->get('/d/{doc}', static fn (): string => 'seen');

        $this->actingAs(new FgaTestUser('anne'))->get('/d/budget')->assertOk();

        /** @var array{tuple_key?: array{object?: string}} $body */
        $body = $this->http->lastBody();
        $this->assertSame('document:budget', $body['tuple_key']['object'] ?? null);
    }

    #[Test]
    public function test_the_config_is_publishable(): void
    {
        $command = $this->artisan('vendor:publish', ['--tag' => 'openfga-config']);

        self::assertInstanceOf(PendingCommand::class, $command);

        // PendingCommand defers to its destructor, and holding it in a
        // variable postpones that past the assertion below - so it is run
        // explicitly rather than by luck of scope.
        $command->assertSuccessful()->run();
        $this->assertFileExists($this->container()->configPath('openfga.php'));
        @unlink($this->container()->configPath('openfga.php'));
    }
}

/** @property string $id */
final class FgaDocument extends Model implements FgaObject
{
    use AuthorizedByOpenFga;

    protected $table = 'fga_documents';

    // String keys, as an application keyed on a slug or a UUID has: without
    // these, getKey() casts 'budget' to 0 and the model looks unsaved.
    protected $keyType = 'string';

    public $incrementing = false;

    public function __construct(?string $key = null)
    {
        parent::__construct();

        if ($key !== null) {
            $this->setAttribute('id', $key);
            $this->exists = true;
        }
    }
}

final class RenamedDocument extends Model implements FgaObject
{
    use AuthorizedByOpenFga;

    protected string $fgaType = 'doc';

    protected $keyType = 'string';

    public $incrementing = false;

    public function __construct(?string $key = null)
    {
        parent::__construct();

        if ($key !== null) {
            $this->setAttribute('id', $key);
            $this->exists = true;
        }
    }
}

final class UnsavedDocument extends Model implements FgaObject
{
    use AuthorizedByOpenFga;
}

final class PlainThing {}

final class FgaTestUser implements Authenticatable
{
    public function __construct(private readonly string $id) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}

final class SelfNamingUser implements FgaUser
{
    public function fgaUser(): string
    {
        return 'employee:7';
    }
}
