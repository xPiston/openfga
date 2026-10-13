<?php

declare(strict_types=1);

namespace Piston\OpenFga;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Finds a PSR-18 client and PSR-17 factories, if the application happens to
 * have php-http/discovery installed.
 *
 * It is a convenience and nothing more: every method returns null when there
 * is nothing to find, and the caller then says what to install. The point is
 * that an application with Guzzle or Symfony's HttpClient already in its
 * vendor directory should not have to wire three interfaces to ask one question.
 */
final class Discovery
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function client(): ?ClientInterface
    {
        if (! class_exists(Psr18ClientDiscovery::class)) {
            return null;
        }

        try {
            return Psr18ClientDiscovery::find();
        } catch (NotFoundException) {
            return null;
        }
    }

    public static function requestFactory(): ?RequestFactoryInterface
    {
        if (! class_exists(Psr17FactoryDiscovery::class)) {
            return null;
        }

        try {
            return Psr17FactoryDiscovery::findRequestFactory();
        } catch (NotFoundException) {
            return null;
        }
    }

    public static function streamFactory(): ?StreamFactoryInterface
    {
        if (! class_exists(Psr17FactoryDiscovery::class)) {
            return null;
        }

        try {
            return Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException) {
            return null;
        }
    }
}
