<?php

declare(strict_types=1);

namespace Piston\OpenFga\Contracts;

/**
 * A user OpenFGA knows by a name of its own.
 *
 * Optional. Without it, the authenticated user becomes
 * `{user_type}:{identifier}` - Laravel's auth identifier, or Symfony's
 * `getUserIdentifier()` - which is right for most
 * applications and wrong for any that key OpenFGA on something other than the
 * primary key, such as a tenant-scoped id or an external subject claim.
 */
interface FgaUser
{
    /** This user as `type:id`. */
    public function fgaUser(): string;
}
