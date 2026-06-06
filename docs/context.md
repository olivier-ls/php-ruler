# Context Access

## Overview

The context is a PHP associative array provided to each evaluation. It holds the variables that the expression can reference. Paths are expressed in **dot-notation**: `cart.total` accesses `$context['cart']['total']`.

This section covers helpers for interacting directly with a context outside of an evaluation:
- Explicit path resolution with or without a default value
- Existence check
- Structured description of the context (typically for a back-office that displays available variables)

The methods are exposed on `ExpressionEvaluator` for convenience but all delegate to `ContextResolver` (a static, stateless class).

## API

```php
public function getContextValue(string $path, array $context): mixed
public function getContextValueOrDefault(string $path, array $context, mixed $default = null): mixed
public function hasContextValue(string $path, array $context): bool
public function describeContext(array $context): array
```

## Behaviors

### `getContextValue(string $path, array $context): mixed`

Resolves a dot-notation path in the context. Throws if the path does not exist.

```php
$ctx = ['cart' => ['total' => 150.0, 'items' => ['apple', 'bread']]];

$eval->getContextValue('cart.total', $ctx);       // 150.0
$eval->getContextValue('cart.items', $ctx);       // ['apple', 'bread']
$eval->getContextValue('cart', $ctx);             // ['total' => 150.0, 'items' => [...]]
$eval->getContextValue('cart.shipping', $ctx);    // UnknownVariableException
$eval->getContextValue('customer.email', $ctx);   // UnknownVariableException
```

**Exceptions thrown**:
- `UnknownVariableException` — path not found. The message indicates the requested path and, if resolution partially succeeded, the failing segment.

Example message: `Unknown variable: "cart.shipping" (failed at "cart.shipping")`. For a path entirely absent from the first segment: `Unknown variable: "customer.email"`.

### `getContextValueOrDefault(string $path, array $context, mixed $default = null): mixed`

Same as `getContextValue()`, but returns `$default` instead of throwing when the path is absent.

```php
$eval->getContextValueOrDefault('cart.shipping', $ctx);          // null (implicit default)
$eval->getContextValueOrDefault('cart.shipping', $ctx, 0);       // 0
$eval->getContextValueOrDefault('cart.total', $ctx, 0);          // 150.0 (present, default ignored)
```

⚠️ Is the default returned if the found value is `null`? **No**: only the absence of the path triggers the default. A `null` value that is present is returned as-is.

```php
$ctx = ['a' => null];
$eval->getContextValueOrDefault('a', $ctx, 'fallback');  // null (present, even if null)
$eval->getContextValueOrDefault('b', $ctx, 'fallback');  // 'fallback' (absent)
```

### `hasContextValue(string $path, array $context): bool`

Pure existence check for the path, without throwing an exception or allocating.

```php
$eval->hasContextValue('cart.total', $ctx);     // true
$eval->hasContextValue('cart.shipping', $ctx);  // false
$eval->hasContextValue('cart', $ctx);           // true (a sub-array counts as "present")
```

### `describeContext(array $context): array`

Returns a structured description of the context as an array, ready to be JSON-serialized. Typically used by a back-office to list available variables for the user (autocompletion, documentation).

```php
$ctx = [
    'cart' => ['total' => 150.0, 'currency' => 'EUR'],
    'customer' => ['vip' => true],
    'tags' => ['php', 'js'],
];

$eval->describeContext($ctx);
// [
//   ['path' => 'cart.total',    'type' => 'number',  'value' => 150.0],
//   ['path' => 'cart.currency', 'type' => 'string',  'value' => 'EUR'],
//   ['path' => 'customer.vip',  'type' => 'boolean', 'value' => true],
//   ['path' => 'tags',          'type' => 'list', 'itemType' => 'string', 'value' => ['php','js']],
// ]
```

**Flattening policy**:
- **Associative arrays** are flattened to dot-notation paths
- **Indexed lists** (as determined by `array_is_list()`) are kept as terminal values and typed `list`

Possible values for the `type` field:
- `'number'` (int and float combined)
- `'string'`
- `'boolean'`
- `'null'`
- `'list'`
- `'unknown'` (unhandled case)

For lists, an additional `itemType` field indicates:
- The common type if all elements share it
- `'mixed'` if elements have different types
- `'unknown'` if the list is empty

**Exceptions thrown**:
- `CircularContextException` — the structure exceeds `MAX_DEPTH` nesting levels (64). In practice, this signals a circular reference (`$ctx['self'] = &$ctx`), since legitimate business contexts never exceed a few levels.

## Design decisions

### Strict dot-notation

The `.` is **always** a path separator. There is no escaping mechanism.

As a result, a key containing a literal `.` is **inaccessible** via these methods. If your data contains dot-keyed entries (e.g. FQDNs), wrap them under a parent:

```php
// Inaccessible:
$ctx = ['foo.bar' => 'value'];
$eval->getContextValue('foo.bar', $ctx);  // UnknownVariableException

// Wrapped:
$ctx = ['data' => ['foo.bar' => 'value']];
$value = $eval->getContextValue('data', $ctx);  // ['foo.bar' => 'value']
// Then $value['foo.bar'] in standard PHP
```

This is consistent with the intended use: the library is designed for business contexts (cart, customer, order), not for technical structures with arbitrary keys.

### Lists treated as terminal values

`describeContext()` does **not** descend into indexed lists. A list is exposed as-is, with its element type. Rationale:
- A list is semantically an aggregate value, not a structure to explore
- Flattening a list into `tags.0`, `tags.1`, `tags.2`... makes no sense for most business use cases (and would prevent `IN` from working on those paths)

`getContextValue('tags', $ctx)` returns the full array, which can then be used with `IN`:

```php
$eval->evaluate('"php" IN tags', $ctx);  // true
```

### `MAX_DEPTH = 64` for cycle detection

`describeContext()` detects circular references via a depth limit (64 levels). This is generous: real business contexts rarely exceed 5–10 levels. Beyond 64, a loop is assumed.

This limit **does not apply** to the other methods (`getContextValue`, `has`, `getOrDefault`): they perform an iterative descent bounded by the requested path, so they are not exposed to cycle risk.

### `ContextResolver` static and stateless

The `ContextResolver` class has no instance state — all its methods are static. A simple choice:
- No instance to pass as a dependency
- No cross-call cache (each resolution starts fresh)
- Idempotent and thread-safe by design

The `ExpressionEvaluator` methods are just an API convenience (`$eval->getContextValue(...)` is strictly equivalent to `ContextResolver::resolve(...)`).

### `array_key_exists` not `isset`

Resolution uses `array_key_exists()`, not `isset()`. As a result, a key present with a `null` value **is considered present**.

```php
$ctx = ['a' => null];
$eval->hasContextValue('a', $ctx);            // true
$eval->getContextValue('a', $ctx);            // null
$eval->getContextValueOrDefault('a', $ctx);   // null (not the default)
```

This is consistent: `null` is a legitimate value in the expression language (`a = null`, `a ?? defaultValue`...), not an absence marker.

## Known limitations

### No wildcards or path expressions

`cart.items[*].price` or `**.email` are not supported. Paths are text constants. For operations on sub-collections, use aggregation functions (`sum`, `min_of`...) or restructure the context.

### No upfront context validation

There is no "is this context valid?" method. `evaluate()` detects unsupported values (objects, closures...) at variable **resolution** time, not at entry. As a result, if a variable holding an object is in the context but never referenced by the expression, its invalidity goes unnoticed.

To validate upfront, walk the context yourself or use `describeContext()` (which will throw on cycles but not on unsupported types).

### Keyword-insensitive root keys

A root context key that collides with a language keyword (`and`, `or`, `not`, `in`, `true`, `false`, `null`) is still accessible via `getContextValue('and', $ctx)` (which goes through `ContextResolver`, not the Lexer). But it is **inaccessible from an expression**:

```php
$ctx = ['in' => 5];
$eval->getContextValue('in', $ctx);    // 5 (OK)
$eval->evaluate('in', $ctx);           // SyntaxErrorException
```

See `language-reference.md` for reserved keywords.
