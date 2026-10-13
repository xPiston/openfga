<?php

declare(strict_types=1);

namespace Piston\OpenFga\Bridge\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Piston\OpenFga\Contracts\FgaObject;
use Piston\OpenFga\Contracts\FgaUser;
use Piston\OpenFga\OpenFga;

/**
 * The bridge between Laravel's Gate and OpenFGA.
 *
 * Registered as a `Gate::before` callback, and the important thing about it is
 * what it does **not** answer. It returns null - abstain - unless all three of
 * these hold:
 *
 * - the ability is a plain relation name, not a policy method;
 * - the first argument is an object that implements FgaObject, or a string
 *   that already looks like `type:id`;
 * - the user can be named to OpenFGA.
 *
 * Anything else falls through to the policies and gates an application already
 * has, untouched. An integration that answered everything would quietly take
 * over authorisation the moment it was installed, which is not a thing a
 * package should do to an application.
 *
 * When it does answer, it answers definitively: OpenFGA saying no is a denial,
 * not an abstention, because the object asked to be authorised this way. And
 * if OpenFGA cannot be reached the exception propagates - the Gate fails
 * closed, which for an authorisation system is the only safe direction.
 */
final class OpenFgaAuthorizer
{
    public function __construct(
        private readonly OpenFga $fga,
        private readonly string $userType = 'user',
    ) {}

    /**
     * @param  list<mixed>  $arguments
     */
    public function __invoke(mixed $user, string $ability, array $arguments = []): ?bool
    {
        // The ability is checked first, and deliberately: it is the only test
        // here that cannot fail. `objectFor()` calls `fgaObject()`, which an
        // unsaved model refuses to answer - so asking it about an ability
        // this bridge was never going to handle would make
        // `Gate::allows('viewAny', $unsavedModel)` throw in an application
        // that worked before this package was installed. Cheap and harmless
        // first, then the rest.
        if (preg_match('/^[a-z_][a-z0-9_\-]*$/i', $ability) !== 1 || self::looksLikeAPolicyMethod($ability)) {
            return null;
        }

        $subject = $this->userFor($user);

        if ($subject === null) {
            return null;
        }

        $object = $this->objectFor($arguments[0] ?? null);

        if ($object === null) {
            return null;
        }

        return $this->fga->check($subject, $ability, $object);
    }

    /**
     * `viewAny`, `forceDelete`, `restore` - Laravel's own convention.
     *
     * OpenFGA relation names are snake_case by universal convention and by
     * every example in its documentation, so a camelCase ability is a policy
     * method and none of this bridge's business. Checked rather than assumed,
     * because the alternative is answering `update` with a relation that does
     * not exist and turning a working policy into a 400.
     */
    private static function looksLikeAPolicyMethod(string $ability): bool
    {
        return preg_match('/[A-Z]/', $ability) === 1;
    }

    /** `type:id` for anything this bridge is willing to authorise. */
    public function objectFor(mixed $argument): ?string
    {
        if ($argument instanceof FgaObject) {
            return $argument->fgaObject();
        }

        if (is_string($argument) && preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*:[^\s:#]+$/', $argument) === 1) {
            return $argument;
        }

        return null;
    }

    /** `type:id` for the authenticated user, or null if it cannot be told. */
    public function userFor(mixed $user): ?string
    {
        if ($user instanceof FgaUser) {
            return $user->fgaUser();
        }

        if ($user instanceof Authenticatable) {
            $identifier = $user->getAuthIdentifier();

            if (is_string($identifier) || is_int($identifier)) {
                $identifier = (string) $identifier;

                return $identifier === '' ? null : $this->userType.':'.$identifier;
            }
        }

        if (is_string($user) && $user !== '') {
            return str_contains($user, ':') ? $user : $this->userType.':'.$user;
        }

        return null;
    }
}
