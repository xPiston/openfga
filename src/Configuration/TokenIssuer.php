<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

/** Exchanges client credentials for an access token. */
interface TokenIssuer
{
    public function fetch(): AccessToken;
}
