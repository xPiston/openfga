<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

/**
 * The tree `expand` returns, kept as it arrived.
 *
 * Deliberately not modelled into classes. The tree's shape is recursive, its
 * node kinds are an open set that OpenFGA adds to, and its only real use is
 * being looked at by a person working out why a check said no. A typed tree
 * here would be a lot of classes that lose information the first time the
 * server learns a new node type - so the raw structure is kept, with the two
 * accessors that answer the questions people actually ask of it.
 */
final class ExpandTree
{
    /** @param array<string, mixed> $tree */
    private function __construct(public readonly array $tree) {}

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $tree = $json['tree'] ?? [];

        /** @var array<string, mixed> $tree */
        return new self(is_array($tree) ? $tree : []);
    }

    /** `document:budget#viewer`, the relation this tree expands. */
    public function name(): ?string
    {
        $root = $this->tree['root'] ?? null;
        $name = is_array($root) ? ($root['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Every user named anywhere in the tree, flattened.
     *
     * A convenience for logging and for tests, not a substitute for
     * `listUsers`: expand goes one level, so a user reachable only through
     * another object's relation is not here - it appears in `usersets()`
     * instead, as the relation still to be followed.
     *
     * @return list<string>
     */
    public function users(): array
    {
        $users = [];
        self::collect($this->tree, 'users', $users);

        return array_values(array_unique($users));
    }

    /**
     * The relations the tree says to follow next, like `folder:b#owner`.
     *
     * Without these, reading `users()` alone is misleading: a relation
     * defined as `[user] or owner` expands into the directly assigned users
     * *and* a computed reference to `owner`, and only the first are users.
     *
     * @return list<string>
     */
    public function usersets(): array
    {
        $usersets = [];
        self::collect($this->tree, 'userset', $usersets);

        return array_values(array_unique($usersets));
    }

    /**
     * Collect every string sitting under $wanted, at any depth.
     *
     * The shape is `leaf.users.users: [...]` - the key appears twice, once as
     * a wrapper object and once as the list. An earlier version matched the
     * key and stopped, which found the wrapper, collected nothing from it and
     * skipped the list inside. So the list is what is matched, and anything
     * else is descended into.
     *
     * @param  list<string>  $found
     */
    private static function collect(mixed $node, string $wanted, array &$found): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === $wanted) {
                if (is_string($value) && $value !== '') {
                    $found[] = $value;

                    continue;
                }

                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $entry) {
                        if (is_string($entry) && $entry !== '') {
                            $found[] = $entry;
                        }
                    }

                    continue;
                }
            }

            self::collect($value, $wanted, $found);
        }
    }
}
