<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

/**
 * 400: OpenFGA refused the request as malformed.
 *
 * Most often a tuple that does not fit the authorization model - a relation
 * that does not exist on that type, or a user type the relation does not
 * accept. The body names which.
 */
final class ValidationError extends ApiError {}
