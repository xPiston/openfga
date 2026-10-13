<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

use Throwable;

/**
 * Every exception this library throws implements this, so an application that
 * wants one catch can have one.
 */
interface OpenFgaException extends Throwable {}
