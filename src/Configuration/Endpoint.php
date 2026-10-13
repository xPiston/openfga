<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

use Piston\OpenFga\Exception\InvalidArgument;

/**
 * Where the server is.
 *
 * One host and nothing else - unlike some platforms, OpenFGA puts every
 * endpoint under a single base URL, and the only split is that store
 * management (`/stores`) sits above a store while everything else sits inside
 * one (`/stores/{id}/check`). That shape is expressed by the two methods here
 * rather than by string concatenation at each call site.
 *
 * The default is the one `docker run openfga/openfga run` listens on, because
 * that is where this library is first run by everybody who installs it.
 */
final class Endpoint
{
    public const DEFAULT_URL = 'http://localhost:8080';

    private readonly string $url;

    public function __construct(string $url = self::DEFAULT_URL)
    {
        $trimmed = rtrim(trim($url), '/');

        if ($trimmed === '') {
            throw InvalidArgument::because('the OpenFGA URL', 'is empty');
        }

        $parts = parse_url($trimmed);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw InvalidArgument::because(
                'the OpenFGA URL',
                'is not an absolute http(s) URL - it needs a scheme and a host, like http://localhost:8080',
                $trimmed,
            );
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw InvalidArgument::because('the OpenFGA URL', 'must be http or https', $trimmed);
        }

        $this->url = $trimmed;
    }

    /** A path above any store, such as `stores`. */
    public function root(string $path = ''): string
    {
        return $this->url.'/'.ltrim($path, '/');
    }

    /** A path inside a store, such as `check`. */
    public function inStore(StoreId $store, string $path = ''): string
    {
        $path = ltrim($path, '/');

        return $this->url.'/stores/'.$store->value().($path === '' ? '' : '/'.$path);
    }

    public function url(): string
    {
        return $this->url;
    }
}
