<?php

declare(strict_types=1);

namespace Piston\OpenFga\Bridge\Laravel\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Makes an Eloquent model answerable by OpenFGA.
 *
 *     final class Document extends Model implements FgaObject
 *     {
 *         use AuthorizedByOpenFga;
 *     }
 *
 *     Gate::allows('viewer', $document);
 *
 * The type defaults to the model's snake_case basename - `Document` becomes
 * `document`, `AssetCategory` becomes `asset_category` - and is overridden
 * with a property when the model's class name and the type in your
 * authorization model have drifted apart, as they do:
 *
 *     protected string $fgaType = 'doc';
 *
 * It refuses to name an unsaved model. A model with no key would become
 * `document:` , which OpenFGA rejects as malformed - but only after a round
 * trip, and with a message about the tuple rather than about the model. Better
 * to say so here.
 */
trait AuthorizedByOpenFga
{
    public function fgaObject(): string
    {
        return $this->fgaType().':'.$this->fgaId();
    }

    public function fgaType(): string
    {
        /** @var string|null $declared */
        $declared = property_exists($this, 'fgaType') ? $this->fgaType : null;

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        $class = static::class;
        $basename = substr($class, (int) strrpos($class, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
    }

    protected function fgaId(): string
    {
        /** @var mixed $key */
        $key = $this instanceof Model ? $this->getKey() : null;

        if (! is_string($key) && ! is_int($key)) {
            $key = null;
        }

        if ($key === null || $key === '' || $key === 0) {
            throw new RuntimeException(sprintf(
                '%s has no key yet, so it cannot be named to OpenFGA. Save it before authorising against it.',
                static::class,
            ));
        }

        return (string) $key;
    }
}
