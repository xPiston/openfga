<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

use RuntimeException;

/** No PSR-18 client was given and none could be discovered. */
final class MissingHttpClient extends RuntimeException implements OpenFgaException
{
    public static function create(): self
    {
        return new self(
            'No PSR-18 HTTP client was given and none could be discovered. Either pass one to the '
            .'constructor, or install one together with php-http/discovery, for example: '
            .'composer require symfony/http-client nyholm/psr7 php-http/discovery',
        );
    }
}
