<?php declare(strict_types=1);
namespace Ols\PhpRuler;

use Ols\PhpRuler\Exception\CircularContextException;
use Ols\PhpRuler\Exception\UnknownVariableException;

final class ContextResolver
{
    /**
     * Maximum nesting depth allowed when flattening a context.
     * Real-world business contexts rarely exceed 5-10 levels;
     * 64 is a generous safety net that catches circular references
     * without triggering false positives on legitimate deep structures.
     */
    private const MAX_DEPTH = 64;

    /**
     * Resolves a dot-notation path within a nested context.
     * 'cart.total' → $context['cart']['total']
     *
     * Limitation — keys containing a literal '.':
     * The '.' is always interpreted as a path separator. A key whose name
     * itself contains a '.' is therefore unreachable through this method;
     * if both a literal key 'a.b' and a nested path a → b exist, only the
     * nested one is ever returned. To expose such data, wrap it under a
     * dot-free key (e.g. ['my' => ['a.b' => X]] accessed via 'my' and read
     * from the returned array).
     *
     * @throws UnknownVariableException
     */
    public static function resolve(string $path, array $context): mixed
    {
        $parts    = explode('.', $path);
        $current  = $context;
        $resolved = [];

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $failedAt = implode('.', [...$resolved, $part]);
                $message  = empty($resolved)
                    ? "Unknown variable: \"$path\""
                    : "Unknown variable: \"$path\" (failed at \"$failedAt\")";
                throw new UnknownVariableException($message, $path);
            }
            $resolved[] = $part;
            $current    = $current[$part];
        }

        return $current;
    }

    /**
     * Checks whether a path exists in the context without throwing an exception.
     *
     * Same limitation as {@see self::resolve()}: keys containing a literal '.'
     * are not reachable — the '.' is always treated as a path separator.
     */
    public static function has(string $path, array $context): bool
    {
        $parts   = explode('.', $path);
        $current = $context;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return false;
            }
            $current = $current[$part];
        }

        return true;
    }

    /**
     * Returns the value at the given path, or a default if the path does not exist.
     *
     * Same limitation as {@see self::resolve()}: keys containing a literal '.'
     * are not reachable — the '.' is always treated as a path separator.
     */
    public static function get(string $path, array $context, mixed $default = null): mixed
    {
        $parts   = explode('.', $path);
        $current = $context;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * Describes the context as a structured array, ready to be JSON-serialized.
     * Each entry exposes the path, type, and value of the variable.
     *
     * Associative arrays are flattened into dot-notation paths.
     * Indexed lists are kept as-is and typed as 'list'.
     *
     * Note — keys containing a literal '.':
     * The produced 'path' uses '.' as separator, so a literal-dot key is
     * indistinguishable from a nested descent in the output. Such keys are
     * unreachable via {@see self::resolve()} anyway; this method reflects
     * the same limitation.
     *
     * Example:
     * [
     *   ['path' => 'cart.total',   'type' => 'number',  'value' => 150.0],
     *   ['path' => 'customer.vip', 'type' => 'boolean', 'value' => true],
     *   ['path' => 'tags',         'type' => 'list', 'itemType' => 'string', 'value' => ['php','js']],
     * ]
     *
     * @throws CircularContextException if the context contains a circular reference
     *                                  or exceeds MAX_DEPTH levels of nesting.
     *
     * @return array<int, array{path: string, type: string, value: mixed}>
     */
    public static function describe(array $context): array
    {
        $result = [];

        foreach (self::flattenRaw($context) as $path => $value) {
            // PHP auto-casts numeric string keys to int when assigning to an array,
            // so $path can be int here even though flattenRaw() cast it to string.
            // Re-cast explicitly to honor describeValue()'s string type-hint.
            $result[] = self::describeValue((string) $path, $value);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Flattens the context into a dot-notation path array.
     * Associative arrays are flattened recursively.
     * Indexed lists (array_is_list) are kept as final values.
     *
     * ['cart' => ['total' => 150.0], 'tags' => ['php', 'js']]
     *   → ['cart.total' => 150.0, 'tags' => ['php', 'js']]
     *
     * @throws CircularContextException if recursion exceeds MAX_DEPTH levels,
     *                                  which in practice catches circular
     *                                  references (e.g. $ctx['self'] = &$ctx).
     */
    private static function flattenRaw(array $context, string $prefix = '', int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            $location = $prefix === '' ? '(root)' : "\"$prefix\"";
            throw new CircularContextException(
                "Context nesting exceeds " . self::MAX_DEPTH . " levels at $location "
                . "(likely a circular reference)."
            );
        }

        $result = [];

        foreach ($context as $key => $value) {
            $path = $prefix === '' ? (string) $key : "$prefix.$key";

            if (is_array($value) && !array_is_list($value)) {
                // Associative array → recurse
                $result = array_merge($result, self::flattenRaw($value, $path, $depth + 1));
            } else {
                // Scalar or indexed list → final value
                $result[$path] = $value;
            }
        }

        return $result;
    }

    /**
     * Builds the description of a value: path, type, value (and itemType for lists).
     *
     * @return array{path: string, type: string, value: mixed, itemType?: string}
     */
    private static function describeValue(string $path, mixed $value): array
    {
        if (is_array($value)) {
            // Always an indexed list here (flattenRaw already flattened associative arrays)
            return [
                'path'     => $path,
                'type'     => 'list',
                'itemType' => self::listItemType($value),
                'value'    => $value,
            ];
        }

        return [
            'path'  => $path,
            'type'  => self::scalarType($value),
            'value' => $value,
        ];
    }

    /**
     * Determines the type of a scalar value.
     */
    private static function scalarType(mixed $value): string
    {
        return match (true) {
            is_int($value) || is_float($value) => 'number',
            is_string($value)                  => 'string',
            is_bool($value)                    => 'boolean',
            is_null($value)                    => 'null',
            default                            => 'unknown',
        };
    }

    /**
     * Determines the type of the elements in a list.
     * Returns the common type if all elements share the same type, 'mixed' otherwise.
     * Returns 'unknown' if the list is empty.
     */
    private static function listItemType(array $list): string
    {
        if (empty($list)) {
            return 'unknown';
        }

        $types = array_unique(array_map(fn($v) => self::scalarType($v), $list));

        return count($types) === 1 ? $types[0] : 'mixed';
    }
}
