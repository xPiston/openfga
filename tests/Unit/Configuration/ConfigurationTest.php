<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Unit\Configuration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Configuration\AccessToken;
use Piston\OpenFga\Configuration\ClientCredentials;
use Piston\OpenFga\Configuration\Endpoint;
use Piston\OpenFga\Configuration\HttpTokenIssuer;
use Piston\OpenFga\Configuration\NoCredentials;
use Piston\OpenFga\Configuration\PreSharedKey;
use Piston\OpenFga\Configuration\StoreId;
use Piston\OpenFga\Configuration\TokenIssuer;
use Piston\OpenFga\Exception\InvalidArgument;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function test_the_default_url_is_the_one_docker_listens_on(): void
    {
        $this->assertSame('http://localhost:8080', (new Endpoint)->url());
    }

    #[Test]
    public function test_the_two_path_shapes(): void
    {
        $endpoint = new Endpoint('https://fga.example.com/');
        $store = StoreId::of('01M4T8JQ0KTTDRR0CGQZ3R2RPE');

        $this->assertSame('https://fga.example.com/stores', $endpoint->root('stores'));
        $this->assertSame(
            'https://fga.example.com/stores/01M4T8JQ0KTTDRR0CGQZ3R2RPE/check',
            $endpoint->inStore($store, 'check'),
        );
        $this->assertSame(
            'https://fga.example.com/stores/01M4T8JQ0KTTDRR0CGQZ3R2RPE',
            $endpoint->inStore($store),
        );
    }

    #[Test]
    #[DataProvider('badUrls')]
    public function test_a_url_that_is_not_one_is_refused(string $url): void
    {
        $this->expectException(InvalidArgument::class);

        new Endpoint($url);
    }

    /** @return iterable<string, array{string}> */
    public static function badUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['localhost:8080'];
        yield 'a file' => ['file:///etc/passwd'];
        yield 'a host alone' => ['fga.example.com'];
        yield 'ftp' => ['ftp://fga.example.com'];
    }

    /**
     * A store id is a ULID, and the mistake worth catching is passing the
     * store's *name*. The message says so rather than saying "invalid".
     */
    #[Test]
    public function test_a_store_name_passed_as_an_id_says_so(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/store \*name\*/');

        StoreId::of('production');
    }

    #[Test]
    public function test_a_ulid_is_accepted_in_either_case(): void
    {
        $this->assertSame('01M4T8JQ0KTTDRR0CGQZ3R2RPE', StoreId::of('01M4T8JQ0KTTDRR0CGQZ3R2RPE')->value());
        $this->assertSame('01m4t8jq0kttdrr0cgqz3r2rpe', StoreId::of('01m4t8jq0kttdrr0cgqz3r2rpe')->value());
    }

    #[Test]
    public function test_crockford_base32_excludes_the_confusable_letters(): void
    {
        // I, L, O and U are not in the alphabet, so an id containing one is
        // not a ULID - most often a transcription of 1, 1, 0 or V.
        $this->expectException(InvalidArgument::class);

        StoreId::of('01M4T8JQ0KTTDRR0CGQZ3R2RPI');
    }

    #[Test]
    public function test_no_credentials_is_a_real_answer(): void
    {
        $this->assertNull(NoCredentials::create()->authorizationHeader());
    }

    #[Test]
    public function test_a_preshared_key_becomes_a_bearer_header(): void
    {
        $this->assertSame('Bearer abc123', PreSharedKey::of('abc123')->authorizationHeader());
        $this->assertSame('Bearer abc123', PreSharedKey::of('  abc123  ')->authorizationHeader());
    }

    /**
     * `Bearer Bearer abc` produces a 401 that reads exactly like a wrong key.
     */
    #[Test]
    public function test_a_key_pasted_with_its_prefix_is_refused_with_the_reason(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/already carries a "Bearer " prefix/');

        PreSharedKey::of('Bearer abc123');
    }

    #[Test]
    public function test_a_key_is_masked_when_dumped(): void
    {
        $dumped = print_r(PreSharedKey::of('super-secret'), true);

        $this->assertStringNotContainsString('super-secret', $dumped);
        $this->assertStringContainsString('redacted', $dumped);
    }

    /**
     * Every shape a deployment writes the issuer in has to reach the same
     * place. These are the forms the official clients handle, and the last two
     * are the ones that break naive concatenation.
     */
    #[Test]
    #[DataProvider('issuers')]
    public function test_the_token_url_is_built_the_way_the_official_clients_build_it(string $issuer, string $expected): void
    {
        $this->assertSame($expected, HttpTokenIssuer::tokenUrl($issuer));
    }

    /** @return iterable<string, array{string, string}> */
    public static function issuers(): iterable
    {
        yield 'a bare host' => ['issuer.example.com', 'https://issuer.example.com/oauth/token'];
        yield 'https given' => ['https://issuer.example.com', 'https://issuer.example.com/oauth/token'];
        yield 'http kept' => ['http://localhost:9000', 'http://localhost:9000/oauth/token'];
        yield 'a trailing slash' => ['https://issuer.example.com/', 'https://issuer.example.com/oauth/token'];
        yield 'a path of its own' => ['https://issuer.example.com/custom/token', 'https://issuer.example.com/custom/token'];
        yield 'a query that must survive' => ['https://issuer.example.com/token?tenant=a', 'https://issuer.example.com/token?tenant=a'];
        yield 'a port' => ['issuer.example.com:8443', 'https://issuer.example.com:8443/oauth/token'];
    }

    #[Test]
    public function test_an_empty_issuer_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);

        HttpTokenIssuer::tokenUrl('  ');
    }

    /**
     * A token is fetched once and reused until it is nearly expired.
     */
    #[Test]
    public function test_an_access_token_is_cached(): void
    {
        $issuer = new class implements TokenIssuer
        {
            public int $calls = 0;

            public function fetch(): AccessToken
            {
                $this->calls++;

                return new AccessToken('token-'.$this->calls, (new DateTimeImmutable)->modify('+1 hour'));
            }
        };

        $credentials = new ClientCredentials($issuer, buffer: 30);

        $headers = [$credentials->authorizationHeader(), $credentials->authorizationHeader()];

        $this->assertSame(['Bearer token-1', 'Bearer token-1'], $headers);
        $this->assertSame(1, $issuer->calls, 'the token is fetched once, not once per request');

        $credentials->forget();

        $this->assertSame('Bearer token-2', $credentials->authorizationHeader());
        $this->assertSame(2, $issuer->calls);
    }

    /**
     * The buffer is the point of AccessToken.
     *
     * A token with two seconds left passes a naive `expiresAt > now` and then
     * fails in flight, because the check and the request are not the same
     * instant.
     */
    #[Test]
    public function test_a_token_about_to_expire_is_not_usable(): void
    {
        $now = new DateTimeImmutable('2026-10-13T12:00:00Z');
        $token = new AccessToken('t', new DateTimeImmutable('2026-10-13T12:00:02Z'));

        $this->assertTrue($token->isUsable($now), 'with no buffer it still has two seconds');
        $this->assertFalse($token->isUsable($now, 30), 'with a thirty second buffer it does not');
    }

    #[Test]
    public function test_a_token_with_no_expiry_is_trusted(): void
    {
        $token = new AccessToken('t', null);

        $this->assertTrue($token->isUsable(new DateTimeImmutable, 300));
    }

    #[Test]
    public function test_an_empty_token_is_never_usable(): void
    {
        $this->assertFalse((new AccessToken('', null))->isUsable(new DateTimeImmutable));
    }

    #[Test]
    public function test_a_token_is_masked_when_dumped(): void
    {
        $dumped = print_r(new AccessToken('super-secret', null), true);

        $this->assertStringNotContainsString('super-secret', $dumped);
    }

    /**
     * The jitter exists so a fleet of workers does not all refresh in the
     * same second when a shared token expires.
     *
     * It is random by design, so it is observed rather than read: the margin
     * is somewhere in [30, 60], so a token with exactly 45 seconds left is
     * reused by some instances and refetched by others. Seeing both outcomes
     * is the jitter; seeing one would mean the margin is fixed.
     */
    #[Test]
    public function test_the_refresh_margin_carries_jitter(): void
    {
        $reused = 0;
        $refetched = 0;

        for ($i = 0; $i < 200; $i++) {
            $issuer = new class implements TokenIssuer
            {
                public int $calls = 0;

                public function fetch(): AccessToken
                {
                    $this->calls++;

                    return new AccessToken('t'.$this->calls, new DateTimeImmutable('+45 seconds'));
                }
            };

            $credentials = new ClientCredentials($issuer);
            $credentials->authorizationHeader();
            $credentials->authorizationHeader();

            $issuer->calls === 1 ? $reused++ : $refetched++;
        }

        $this->assertGreaterThan(0, $reused, 'with a margin under 45s the token should sometimes be reused');
        $this->assertGreaterThan(0, $refetched, 'with a margin over 45s it should sometimes be refetched');
        $this->assertSame(200, $reused + $refetched);
    }
}
