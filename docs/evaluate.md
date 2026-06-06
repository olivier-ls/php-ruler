# Main Evaluation

## Overview

Main evaluation is the most common entry point of the library: pass an expression as a string, a context (array of variables), and get back the evaluated result. This is **strict mode**: any anomaly (missing variable, type error, division by zero, etc.) throws an exception.

For a lenient mode that collects missing variables instead of throwing, see `evaluate-safe.md`.

Main evaluation is performed via the `ExpressionEvaluator` class (the library's public entry point).

## API

All methods are public and exposed on `ExpressionEvaluator`.

### Evaluation from an expression (string)

```php
public function evaluate(string $expression, array $context): mixed
public function evaluateBoolean(string $expression, array $context): bool
public function evaluateNumeric(string $expression, array $context): float
```

### Evaluation from a pre-compiled AST

```php
public function evaluateAst(Node $ast, array $context): mixed
public function evaluateAstBoolean(Node $ast, array $context): bool
public function evaluateAstNumeric(Node $ast, array $context): float
```

The `*Ast()` variants bypass the Lexer and Parser when an AST is already compiled (typically via `getAst()` or `importAst()`, see `ast-management.md`). Evaluation semantics are strictly identical to the string variants.

## Behaviors

### `evaluate(string $expression, array $context): mixed`

Evaluates the expression in the given context and returns the result. The return type depends on the expression: `int`, `float`, `string`, `bool`, `null`, or `array` (only when the expression is a list literal or a function returning an array).

**Internal pipeline**: lexing → parsing (with AST caching, see `ast-management.md`) → evaluation.

**Examples**:
```php
$eval = new ExpressionEvaluator();

$eval->evaluate('1 + 2', []);                         // 3 (int)
$eval->evaluate('cart.total > 100', ['cart' => ['total' => 150]]);  // true (bool)
$eval->evaluate('upper(name)', ['name' => 'alice']);  // 'ALICE' (string)
$eval->evaluate('a ?? 0', ['a' => null]);             // 0
$eval->evaluate('[1, 2, 3]', []);                     // [1, 2, 3]
```

**Exceptions thrown**:
- `SyntaxErrorException` — malformed expression (lex or parse)
- `UnknownVariableException` — variable referenced but absent from the context
- `TypeErrorException` — operation on incompatible types, NaN/INF, division by zero, integer overflow, wrong function arity
- `EvaluatorException` — AST depth exceeded (200 levels), unknown AST node (corruption)
- `CircularContextException` — not thrown by `evaluate()` directly (only by `describeContext()`)

### `evaluateBoolean(string $expression, array $context): bool`

Same as `evaluate()`, but validates that the result is a strict boolean.

**Examples**:
```php
$eval->evaluateBoolean('a > 0', ['a' => 5]);    // true
$eval->evaluateBoolean('1 + 1', []);            // TypeErrorException — result is int, not bool
$eval->evaluateBoolean('a = 1', ['a' => 1]);    // true (the '=' operator does return a bool)
```

**Additional exceptions**:
- `TypeErrorException` if the result is not strictly a `bool` (no truthy/falsy coercion)

### `evaluateNumeric(string $expression, array $context): float`

Same as `evaluate()`, but validates that the result is numeric (int or float) and casts it to `float`.

**Always returns `float`**: even for an integer calculation, the return value is a `float`. `evaluateNumeric('5 + 3', [])` returns `8.0`, not `8`. This is intentional: a uniform, predictable output type. In a business context (prices, rates, quantities), `float` is the standard exchange value. If the caller needs an exact `int`, it must normalize afterwards.

**Examples**:
```php
$eval->evaluateNumeric('1 + 2', []);         // 3.0 (float, even for an int calculation)
$eval->evaluateNumeric('cart.total * 1.2', ['cart' => ['total' => 100]]);  // 120.0
$eval->evaluateNumeric('upper(name)', ['name' => 'x']);  // TypeErrorException
```

**Additional exceptions**:
- `TypeErrorException` if the result is neither `int` nor `float`

### `evaluateAst*` variants

Identical semantics to the three methods above, but take a `Node` object (AST) as the first argument instead of a string. Skips the lex/parse phase. Useful for:
- Evaluating the same expression multiple times with different contexts (parse once)
- Evaluating an AST loaded from an external cache via `importAst()`

```php
$ast = $eval->getAst('a > threshold');  // parse once
foreach ($items as $item) {
    if ($eval->evaluateAstBoolean($ast, ['a' => $item, 'threshold' => 10])) { /* ... */ }
}
```

## Design decisions

### Strict mode by default

Main evaluation tolerates **no silent anomaly**. This policy is applied consistently:

- A missing variable throws (no implicit fallback to `null`)
- Logical operators (`AND`, `OR`, `NOT`, ternary) require a strict `bool` — no PHP-style truthy/falsy coercion (where `"false" AND true` would be `true`)
- Arithmetic operators require `int|float` — `-null` is not `0`, it is a `TypeErrorException`
- Comparisons of incompatible types throw (e.g. `'a' > 5`)
- Direct array comparison (`= / !=`) is forbidden (use `IN`)

The goal is the "no surprise" principle: PHP developers are accustomed to silent coercions, which makes expressions hard to audit. The library rejects this tolerance.

### Transparent AST cache

`evaluate()` (and all string variants) go through `getAst()`, which maintains an internal LRU cache (max size 500 entries by default) indexed by the canonicalized expression. Evaluating the same expression twice does not re-parse.

Canonicalization is limited to collapsing whitespace runs **outside quoted literals**. As a result, `"1+1"` and `"1 + 1"` produce two distinct cache entries (but the same AST). See `ast-management.md` for details and rationale.

### Depth guard

A maximum evaluation depth of 200 levels is enforced (`MAX_EVAL_DEPTH` in `Evaluator`). Beyond this, `EvaluatorException` is thrown. This limit protects against:
- Pathological expressions (excessive nesting)
- Cyclic ASTs that may have passed `importAst()` validation

The counter is shared between strict and safe modes and is reset on exception, so a failed evaluation does not "pollute" the next one.

### Incoming context value validation

When a variable is resolved from the context (`resolveVariable`), its value is validated: only scalars, `null`, and arrays of scalars/null are accepted. Objects, closures, and resources throw a `TypeErrorException` at resolution time with a message identifying the offending path (e.g. `Variable "cart.items[0]"`).

This validation recurses into indexed arrays (associative arrays are already decomposed into dot-notation paths by `ContextResolver`).

### NaN and INF forbidden in the pipeline

NaN and INF are not allowed to participate in calculations or comparisons. This rule is enforced **at every operator** (arithmetic, comparison, equality, unary `-`):
- A NaN/INF value coming from the context (`resolveVariable`) or from a custom function return is **not** rejected at that point: it can transit until `is_finite()`.
- As soon as it enters an operator, `TypeErrorException` is thrown.

**Escape hatch**: the `is_finite()` function allows explicit testing (returns `bool` without throwing). This is the only way to inspect a NaN/INF coming from the context or a custom function.

### Custom function exception handling

When a function registered via `registerFunction()` throws an exception during execution:
- Library exceptions (`EvaluatorException` and descendants: `TypeErrorException`, `UnknownVariableException`, etc.) propagate **unchanged**. This allows a custom function to delegate to `evaluate()` or `getContextValue()` internally.
- **Any other exception** (`RuntimeException`, `LogicException`, `Error`, etc.) is wrapped in a `TypeErrorException` with the original exception as `previous`.

Guarantee: `evaluate()` **never** propagates a raw PHP exception from user code. See `functions.md` for details on registration and arity.

## Known limitations

### Variables named like keywords

The identifiers `and`, `or`, `not`, `in` (case-insensitive) are tokenized as logical operators before being interpreted as identifiers. As a result, a root variable named `In`, `AND`, etc. is inaccessible directly.

```php
$eval->evaluate('In', ['In' => 5]);  // SyntaxErrorException
```

Compound paths are not affected as long as the leading segment is not a keyword: `user.in` works. To expose data whose root key collides with a keyword, wrap it under a parent: `['root' => ['in' => $value]]` accessible via `root.in`.

Reserved keywords (case-insensitive): `and`, `or`, `not`, `in`, `true`, `false`, `null`.

### Keys containing a literal dot

The `.` is always interpreted as a path separator. A context key that contains a literal `.` is inaccessible via dot-notation. See `context.md`.

### `UnknownVariableException` thrown from a custom function

If a custom function calls `getContextValue('x', [])` internally and throws `UnknownVariableException`, this exception passes through `evaluate()` unchanged — but safe mode (`evaluateSafe()`) **will not be able to collect** this variable in `missingVars` (the library cannot know whether the missing variable was expected or not). Custom function authors should either pass a default to `getContextValue()`, or catch the exception themselves.
