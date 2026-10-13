<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

/** 404: no such store, model, or - on some paths - no such object at all. */
final class NotFound extends ApiError {}
