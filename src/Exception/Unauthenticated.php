<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

/**
 * 401 or 403.
 *
 * Worth distinguishing from a failed check, and the distinction is the whole
 * point of the class: a 403 here means *this SDK* may not talk to OpenFGA,
 * while `check()` returning false means the *user* may not touch the object.
 * Conflating the two produces an application that opens up when its
 * credentials expire.
 */
final class Unauthenticated extends ApiError {}
