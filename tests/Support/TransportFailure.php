<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/** A PSR-18 transport failure, for the case where the server is simply gone. */
final class TransportFailure extends RuntimeException implements ClientExceptionInterface {}
