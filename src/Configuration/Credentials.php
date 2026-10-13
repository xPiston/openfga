<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

/**
 * How this client proves who it is - and OpenFGA has three answers, which is
 * unusual enough to be worth a type rather than a nullable string.
 *
 * A self-hosted server started with no flags accepts anything (None). The same
 * server started with `--authn-method=preshared` wants a key it was given on
 * the command line (PreSharedKey). A managed offering wants an OAuth2 access
 * token obtained from an issuer (ClientCredentials), which means a second HTTP
 * call, a cached token and an expiry - so that one is not a string at all.
 *
 * Returning null is a real answer here, not a missing one. It is what the
 * development default needs, and an SDK that insisted on a token would make
 * `docker run openfga/openfga run` unusable.
 */
interface Credentials
{
    /** The Authorization header value, or null when the server wants none. */
    public function authorizationHeader(): ?string;
}
