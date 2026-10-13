<?php

declare(strict_types=1);

namespace Piston\OpenFga\Bridge\Symfony;

use Piston\OpenFga\Contracts\FgaObject;
use Piston\OpenFga\Contracts\FgaUser;
use Piston\OpenFga\OpenFga;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A Security voter backed by OpenFGA.
 *
 *     #[IsGranted('viewer', subject: 'document')]
 *     public function show(Document $document): Response
 *
 *     // or
 *     $this->denyAccessUnlessGranted('viewer', $document);
 *
 * It votes only on subjects that implement FgaObject, or on strings that
 * already look like `type:id`. For everything else `supports()` returns false
 * and Symfony's access decision manager never asks it - so existing voters and
 * attributes keep deciding what they decided before.
 *
 * The attribute is the relation name. That means a relation called `ROLE_X`
 * would collide with Symfony's role voter, which is why only lower-case
 * relation-shaped attributes are supported: `viewer`, `can_read`,
 * `asset_editor`. An attribute in Symfony's `ROLE_` convention is left alone.
 *
 * If OpenFGA cannot be reached, the exception propagates rather than becoming
 * a denial. Symfony turns an uncaught exception into a 500 and a denial into a
 * 403, and for an authorisation system those must stay different: a 403 says
 * "we asked and the answer was no", and this cannot say that if nobody
 * answered.
 */
/**
 * @extends Voter<string, mixed>
 */
final class OpenFgaVoter extends Voter
{
    public function __construct(
        private readonly OpenFga $fga,
        private readonly string $userType = 'user',
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        // The attribute is checked first, and deliberately: Symfony calls
        // this for every attribute against every subject, and `objectFor()`
        // asks the subject to name itself - which an unsaved entity refuses
        // to do. Checking the attribute first means `isGranted('ROLE_ADMIN',
        // $unsavedEntity)` keeps working, where asking the subject first
        // would turn it into an exception.
        //
        // Lower case only: relation names are snake_case by convention and
        // in every OpenFGA example, so `ROLE_ADMIN` and `PUBLIC_ACCESS` are
        // left to the voters that own them.
        if (preg_match('/^[a-z_][a-z0-9_\-]*$/', $attribute) !== 1) {
            return false;
        }

        return $this->objectFor($subject) !== null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $object = $this->objectFor($subject);

        if ($object === null) {
            return false;
        }

        $user = $this->userFor($token->getUser());

        if ($user === null) {
            // Nobody is authenticated, so nothing can be granted. A denial
            // rather than an abstention: the subject asked to be authorised
            // by OpenFGA, and an anonymous request holds no tuples.
            return false;
        }

        return $this->fga->check($user, $attribute, $object);
    }

    public function objectFor(mixed $subject): ?string
    {
        if ($subject instanceof FgaObject) {
            return $subject->fgaObject();
        }

        if (is_string($subject) && preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*:[^\s:#]+$/', $subject) === 1) {
            return $subject;
        }

        return null;
    }

    public function userFor(mixed $user): ?string
    {
        if ($user instanceof FgaUser) {
            return $user->fgaUser();
        }

        if ($user instanceof UserInterface) {
            $identifier = $user->getUserIdentifier();

            return $identifier === '' ? null : $this->userType.':'.$identifier;
        }

        return null;
    }
}
