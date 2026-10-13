<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

use RuntimeException;
use Throwable;

/** The request never got an answer, or got one that was not JSON. */
final class TransportFailed extends RuntimeException implements OpenFgaException
{
    public static function unreachable(Throwable $previous): self
    {
        return new self('the OpenFGA server could not be reached: '.$previous->getMessage(), 0, $previous);
    }

    public static function notJson(int $status, string $body): self
    {
        $excerpt = mb_substr(trim($body), 0, 200);

        return new self(sprintf(
            'the OpenFGA server answered %d with something that is not JSON: %s',
            $status,
            $excerpt === '' ? '(an empty body)' : $excerpt,
        ));
    }
}
