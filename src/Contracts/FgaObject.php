<?php

declare(strict_types=1);

namespace Piston\OpenFga\Contracts;

/**
 * Something OpenFGA can be asked about.
 *
 * Implementing this is how a model **opts in** to being authorised by
 * OpenFGA, and opting in is deliberate: Laravel's Gate hook and Symfony's
 * voter both answer only for objects that say they want it, and abstain for
 * everything else - so installing this package changes the behaviour of
 * nothing until a model asks it to.
 *
 * Framework-neutral on purpose: one interface, implemented once, understood by
 * both bridges and by plain PHP.
 */
interface FgaObject
{
    /** This object as `type:id`, the way OpenFGA names it. */
    public function fgaObject(): string;
}
