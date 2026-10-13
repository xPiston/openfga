<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

/**
 * No authentication, which is what a plain `openfga run` expects.
 *
 * It is a class rather than a null so that "nobody configured this" and
 * "this server deliberately wants no credentials" are different states. The
 * first is a bug worth an exception; the second is Tuesday.
 */
final class NoCredentials implements Credentials
{
    public static function create(): self
    {
        return new self;
    }

    public function authorizationHeader(): ?string
    {
        return null;
    }
}
