<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Bridge;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Bridge\Symfony\OpenFgaBundle;
use Piston\OpenFga\Bridge\Symfony\OpenFgaVoter;
use Piston\OpenFga\Contracts\FgaObject;
use Piston\OpenFga\Contracts\FgaUser;
use Piston\OpenFga\OpenFga;
use Piston\OpenFga\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The bundle compiling a **real** Symfony container.
 *
 * Not a mocked one: a container that only fails at compile time is a container
 * that fails in somebody else's deployment, and this suite is what caught a
 * service definition using `__construct` as a factory - which Symfony does not
 * allow and which no unit test would have noticed.
 */
final class SymfonyBridgeTest extends TestCase
{
    /** @param array<string, mixed> $config */
    #[Test]
    #[DataProvider('configurations')]
    public function test_the_container_compiles(array $config, ?string $store, ?string $model, bool $voter): void
    {
        $container = self::compile($config);

        $fga = $container->get(OpenFga::class);

        $this->assertInstanceOf(OpenFga::class, $fga);
        $this->assertSame($store, $store === null ? null : (string) $fga->store());
        $this->assertSame($model, $fga->modelId());
        $this->assertSame($voter, $container->has(OpenFgaVoter::class));
    }

    /** @return iterable<string, array{array<string, mixed>, ?string, ?string, bool}> */
    public static function configurations(): iterable
    {
        yield 'bare' => [[], null, null, true];
        yield 'a store and a model' => [
            ['store' => '01M4T8JQ0KTTDRR0CGQZ3R2RPE', 'model' => '01M4T8JQ1QC6Z7T15Z572NQ0YW'],
            '01M4T8JQ0KTTDRR0CGQZ3R2RPE',
            '01M4T8JQ1QC6Z7T15Z572NQ0YW',
            true,
        ];
        yield 'a preshared key' => [
            ['credentials' => ['method' => 'preshared', 'token' => 'secret']],
            null, null, true,
        ];
        yield 'client credentials' => [
            ['credentials' => [
                'method' => 'client_credentials',
                'issuer' => 'issuer.example.com',
                'client_id' => 'id',
                'client_secret' => 'secret',
                'audience' => 'https://api.fga.example',
            ]],
            null, null, true,
        ];
        yield 'the voter turned off' => [['voter' => false], null, null, false];
    }

    #[Test]
    public function test_the_voter_is_tagged_so_symfony_finds_it(): void
    {
        $container = self::compile([]);

        $this->assertNotSame([], $container->getDefinition(OpenFgaVoter::class)->getTag('security.voter'));
    }

    #[Test]
    public function test_an_unknown_auth_method_is_refused_at_compile_time(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::compile(['credentials' => ['method' => 'nonsense']]);
    }

    #[Test]
    public function test_a_preshared_key_reaches_the_request(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');
        $container = self::compile(
            ['store' => '01M4T8JQ0KTTDRR0CGQZ3R2RPE', 'credentials' => ['method' => 'preshared', 'token' => 'a-key']],
            $http,
        );

        $fga = $container->get(OpenFga::class);

        $this->assertInstanceOf(OpenFga::class, $fga);
        $fga->check('user:anne', 'viewer', 'doc:1');

        $this->assertSame('Bearer a-key', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    #[Test]
    public function test_the_voter_answers_for_an_object_that_opted_in(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');
        $voter = $this->voter($http);

        $vote = $voter->vote($this->token(new SymfonyTestUser('anne')), new SymfonyDocument('budget'), ['viewer']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $vote);
        $this->assertSame([
            'tuple_key' => ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'document:budget'],
        ], $http->lastBody());
    }

    #[Test]
    public function test_the_voter_denies_when_openfga_says_no(): void
    {
        $voter = $this->voter(FakeHttpClient::replying('{"allowed":false}'));

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token(new SymfonyTestUser('anne')), new SymfonyDocument('budget'), ['viewer']),
        );
    }

    /** The important one: a subject that did not opt in is not ours. */
    #[Test]
    public function test_the_voter_abstains_for_everything_else(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');
        $voter = $this->voter($http);
        $token = $this->token(new SymfonyTestUser('anne'));

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new PlainEntity, ['viewer']));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['viewer']));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new SymfonyDocument('b'), ['ROLE_ADMIN']));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new SymfonyDocument('b'), ['PUBLIC_ACCESS']));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, 'not an object', ['viewer']));
        $this->assertSame(0, $http->callCount(), 'OpenFGA was never asked');
    }

    /**
     * The ordering fix: a role on an unsaved entity must not make the voter
     * ask it to name itself.
     */
    #[Test]
    public function test_a_role_on_an_unsaved_entity_does_not_throw(): void
    {
        $voter = $this->voter(FakeHttpClient::replying('{}'));

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->token(new SymfonyTestUser('anne')), new UnsavedEntity, ['ROLE_ADMIN']),
        );
    }

    #[Test]
    public function test_an_anonymous_request_is_denied_not_abstained(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');
        $voter = $this->voter($http);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token(null), new SymfonyDocument('budget'), ['viewer']),
            'the subject asked to be authorised by OpenFGA, and an anonymous request holds no tuples',
        );
        $this->assertSame(0, $http->callCount());
    }

    #[Test]
    public function test_a_string_subject_is_accepted_when_it_names_an_object(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter($http)->vote($this->token(new SymfonyTestUser('anne')), 'document:budget', ['viewer']),
        );
    }

    #[Test]
    public function test_a_user_can_name_itself(): void
    {
        $voter = $this->voter(FakeHttpClient::replying('{}'));

        $this->assertSame('user:anne', $voter->userFor(new SymfonyTestUser('anne')));
        $this->assertSame('employee:7', $voter->userFor(new SelfNamingSymfonyUser));
        $this->assertNull($voter->userFor(null));
    }

    #[Test]
    public function test_the_user_type_is_configurable(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');
        $voter = new OpenFgaVoter(new OpenFga(\Piston\OpenFga\Tests\Support\Fga::client($http)), 'employee');

        $voter->vote($this->token(new SymfonyTestUser('anne')), new SymfonyDocument('b'), ['viewer']);

        /** @var array{tuple_key?: array{user?: string}} $body */
        $body = $http->lastBody();

        $this->assertSame('employee:anne', $body['tuple_key']['user'] ?? null);
    }

    private function voter(FakeHttpClient $http): OpenFgaVoter
    {
        return new OpenFgaVoter(new OpenFga(\Piston\OpenFga\Tests\Support\Fga::client($http)));
    }

    private function token(?UserInterface $user): TokenInterface
    {
        return new class($user) implements TokenInterface
        {
            public function __construct(private readonly ?UserInterface $user) {}

            public function getUser(): ?UserInterface
            {
                return $this->user;
            }

            public function getUserIdentifier(): string
            {
                return $this->user?->getUserIdentifier() ?? '';
            }

            public function getRoleNames(): array
            {
                return [];
            }

            public function setUser(UserInterface $user): void {}

            public function eraseCredentials(): void {}

            /** @return array<string, mixed> */
            public function getAttributes(): array
            {
                return [];
            }

            /** @param array<string, mixed> $attributes */
            public function setAttributes(array $attributes): void {}

            public function hasAttribute(string $name): bool
            {
                return false;
            }

            public function getAttribute(string $name): mixed
            {
                return null;
            }

            public function setAttribute(string $name, mixed $value): void {}

            public function __toString(): string
            {
                return 'token';
            }

            public function __serialize(): array
            {
                return [];
            }

            /** @param array<array-key, mixed> $data */
            public function __unserialize(array $data): void {}
        };
    }

    /** @param array<string, mixed> $config */
    private static function compile(array $config, ?ClientInterface $http = null): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $factory = new Psr17Factory;
        $container->setDefinition(ClientInterface::class, self::service($http ?? $factory, $http === null));
        $container->setDefinition(RequestFactoryInterface::class, self::service($factory));
        $container->setDefinition(StreamFactoryInterface::class, self::service($factory));

        $extension = (new OpenFgaBundle)->getContainerExtension();

        if ($extension === null) {
            throw new RuntimeException('the bundle has no container extension');
        }

        $extension->load([$config], $container);

        $container->getDefinition(OpenFga::class)->setPublic(true);

        if ($container->hasDefinition(OpenFgaVoter::class)) {
            $container->getDefinition(OpenFgaVoter::class)->setPublic(true);
        }

        $container->compile();

        return $container;
    }

    private static function service(object $instance, bool $psr17 = false): Definition
    {
        $definition = new Definition($psr17 ? \Symfony\Component\HttpClient\Psr18Client::class : $instance::class);
        $definition->setPublic(true);

        if (! $psr17) {
            $definition->setSynthetic(false);
            $definition->setFactory([self::class, 'passThrough']);
            $definition->setArguments([spl_object_id($instance)]);
            self::$instances[spl_object_id($instance)] = $instance;
        }

        return $definition;
    }

    /** @var array<int, object> */
    private static array $instances = [];

    public static function passThrough(int $id): object
    {
        return self::$instances[$id];
    }
}

final class SymfonyDocument implements FgaObject
{
    public function __construct(private readonly string $id) {}

    public function fgaObject(): string
    {
        return 'document:'.$this->id;
    }
}

final class UnsavedEntity implements FgaObject
{
    public function fgaObject(): string
    {
        throw new RuntimeException('this entity has no key yet');
    }
}

final class PlainEntity {}

final class SymfonyTestUser implements UserInterface
{
    /** @param non-empty-string $id */
    public function __construct(private readonly string $id) {}

    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}

final class SelfNamingSymfonyUser implements FgaUser, UserInterface
{
    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return '7';
    }

    public function fgaUser(): string
    {
        return 'employee:7';
    }
}
