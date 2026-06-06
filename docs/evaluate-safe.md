# Safe Evaluation

## Overview

Safe mode is a lenient variant of evaluation: instead of throwing `UnknownVariableException` when a variable is absent from the context, the engine collects the list of missing variables and returns a `SafeResult` object that distinguishes three cases:
- **Success**: all required variables were present → the result is available
- **Partial failure**: at least one required variable was missing → the list is exposed
- **Fatal error**: a non-recoverable issue (type error, corrupted AST, etc.) → the exception is thrown as in strict mode

Safe mode is typically useful in a back-office to validate user rules: "can this rule be evaluated in the current context, or is there missing data to resolve it?" For more detailed diagnostics (node-by-node, with intermediate values), see the Explainer in `explainer.md`.

**Core principle**: `missingVars` answers the question *"what was required but absent?"*, not *"what was absent in the expression?"*. This distinction is subtle but structural — a short-circuited node does not contribute to the missing list, because its absence did not prevent the computation.

## API

```php
public function evaluateSafe(string $expression, array $context): SafeResult
public function evaluateSafeAst(Node $ast, array $context): SafeResult
```

And the `SafeResult` class:

```php
final class SafeResult
{
    public readonly bool  $success;
    public readonly mixed $value;
    public readonly array $missingVars;  // string[]

    public function getValue(): mixed;              // throws LogicException if !success
    public function getValueOr(mixed $default): mixed;
}
```

## Behaviors

### `evaluateSafe(string $expression, array $context): SafeResult`

Evaluates the expression. Always returns a `SafeResult` (never throws on missing variable).

**Return shape**:
- If no variable was missing: `SafeResult(success: true, value: <result>, missingVars: [])`
- If one or more required variables were missing: `SafeResult(success: false, value: null, missingVars: ['path1', 'path2', ...])`

The `missingVars` list is deduplicated and **sorted alphabetically** (consistent with `extractVariables()` and `getFunctions()`).

**Exceptions thrown**:
- `SyntaxErrorException` — malformed expression (same policy as strict mode)
- `TypeErrorException` — type error **unrelated to a missing variable** (e.g. `"hello" AND true` always throws)
- `EvaluatorException` — depth exceeded, corrupted AST
- `CircularContextException` — not thrown directly

**Core guarantee**: `UnknownVariableException` is caught and transformed into an entry in `missingVars`. All other exceptions propagate.

### `evaluateSafeAst(Node $ast, array $context): SafeResult`

Identical but operates on an already-compiled AST. Strictly identical semantics.

### `SafeResult::getValue(): mixed`

Returns `$this->value` if `success` is `true`. Throws `LogicException` otherwise, with a message listing the missing variables.

This method exists to **force the caller to explicitly handle the failure case**. Silently receiving `null` would be ambiguous — `null` can be the actual result of a successful expression (e.g. `evaluateSafe('a ?? null', ['a' => null])` returns `SafeResult(true, null, [])`).

### `SafeResult::getValueOr(mixed $default): mixed`

Returns `$this->value` if `success` is `true`, otherwise `$default`. An alternative without `try/catch`.

```php
$result = $eval->evaluateSafe('cart.total > 100', $context);
$shouldDisplay = $result->getValueOr(false);  // false if data is missing
```

⚠️ Note: `getValueOr(null)` is ambiguous (impossible to distinguish "success with null" from "failure"). In that case, check `$result->success` explicitly.

## Detailed semantics

This is the most nuanced part. The rules below are **fixed and intentional**; they are explicitly documented in the code.

### Single variable

```php
evaluateSafe('a', ['a' => 5]);    // SafeResult(true,  5,    [])
evaluateSafe('a', []);            // SafeResult(false, null, ['a'])
evaluateSafe('a', ['a' => null]); // SafeResult(true,  null, [])  ← null is a value, not an absence
```

### `??` operator (null-coalescing)

`??` is designed to handle absence: if the left operand is absent or `null`, the right operand is evaluated. Variables absent from the **left side** are **not** reported in `missingVars` — their absence is the nominal case for `??`, not a failure.

```php
evaluateSafe('a ?? b', ['a' => 5]);                  // SafeResult(true,  5,   [])
evaluateSafe('a ?? b', ['a' => null, 'b' => 10]);    // SafeResult(true,  10,  [])
evaluateSafe('a ?? b', ['b' => 10]);                 // SafeResult(true,  10,  [])  ← 'a' absent NOT reported
evaluateSafe('a ?? b', []);                          // SafeResult(false, null, ['b'])  ← only 'b' is reported
evaluateSafe('a ?? b', ['a' => null]);               // SafeResult(false, null, ['b'])  ← 'b' was needed because 'a' was null
```

**Rationale**: only what was *ultimately required* and missing is reported. If `a` is absent, `??` does its job and moves right — no reason to surface `a` as missing.

### AND / OR short-circuits

Logical operators short-circuit as in native PHP (and as in strict mode). In safe mode, **a short-circuited node does not contribute to `missingVars`**: its absence did not prevent resolution.

```php
// AND with certain-false left → right not evaluated, its missing vars not reported
evaluateSafe('false AND <missing>', []);     // SafeResult(true, false, [])

// OR with certain-true left → right not evaluated
evaluateSafe('true OR <missing>', []);       // SafeResult(true, true, [])

// Missing left → right is still evaluated, left is reported
evaluateSafe('a AND false', []);             // SafeResult(false, null, ['a'])
evaluateSafe('a OR true', []);               // SafeResult(false, null, ['a'])
evaluateSafe('a AND b', []);                 // SafeResult(false, null, ['a', 'b'])
```

**Special case**: `<missing> AND false` or `<missing> OR true`. The result is *determined* by the right side (false forces AND to false, true forces OR to true), but the missing left is **still reported**:

```php
evaluateSafe('a AND false', []);   // SafeResult(false, null, ['a'])  — not SafeResult(true, false, [])
evaluateSafe('a OR true', []);     // SafeResult(false, null, ['a'])  — not SafeResult(true, true,  [])
```

**Rationale**: `success` answers the question *"was the context complete?"*, not just *"could the result be inferred?"*. A caller receiving `success: false` must know the context was incomplete, regardless of whether a value could be derived.

### Ternary

Only the taken branch is visited. Missing vars from the untaken branch are not reported.

```php
evaluateSafe('a > 0 ? b : c', ['a' => 5, 'b' => 'yes']);      // SafeResult(true,  'yes', [])
evaluateSafe('a > 0 ? b : c', ['a' => -1, 'c' => 'no']);      // SafeResult(true,  'no',  [])
evaluateSafe('a > 0 ? b : c', ['a' => 5]);                    // SafeResult(false, null,  ['b'])  ← 'c' not reported
evaluateSafe('a > 0 ? b : c', []);                            // SafeResult(false, null,  ['a'])  ← condition missing, branches not visited
```

If the **condition** is missing, no branch is visited — their potential missing vars are not reported.

### Type errors not absorbed

Safe mode handles **only** missing variables. Any other error (incompatible type, division by zero, NaN/INF, wrong function arity...) throws just as in strict mode.

```php
evaluateSafe('"hello" AND true', []);     // TypeErrorException — "hello" is not a bool
evaluateSafe('1 / 0', []);                // TypeErrorException — division by zero
evaluateSafe('a AND true', ['a' => 5]);   // TypeErrorException — 5 is not a bool
```

**Rationale**: "safe" means *"`UnknownVariableException` is caught and collected, not globally suppressed"*. A type error is a code bug, not a data problem — masking it would send a false signal to the caller.

### Type-error priority over missing in AND/OR/ternary

When the left side of an AND/OR (or the condition of a ternary) **is resolved** but is not a boolean, `TypeErrorException` is thrown **before** considering the right side. This is consistent with strict mode.

```php
evaluateSafe('5 AND b', ['b' => true]);    // TypeErrorException — not SafeResult(false, ..., ['b'])
evaluateSafe('a AND b', []);               // SafeResult(false, null, ['a', 'b']) — 'a' was not resolved, no bool assertion on it
```

When the left side **could not be resolved** (missing), no type assertion is made on it — we simply do not know what it would have been.

## Design decisions

### Shared depth counter

Safe mode shares the same `evalDepth` counter as strict mode. The `MAX_EVAL_DEPTH` limit (200) applies to both. This also ensures that cross-recursive calls (`evalSafe` → `callFunction` → custom function calling `evaluate()` → `evalSafe`) remain bounded.

### `null` as ambiguous internal sentinel

Internally, `evalSafe` returns `null` as a sentinel when a sub-tree has accumulated missing vars. But since `null` can also be a legitimate value (e.g. the result of `a ?? null`), the caller **must always check `missingVars`** to distinguish the two cases. This is why `SafeResult` only returns `value: null` on failure — the output contract is unambiguous for the end user.

### Custom functions: no transparent collection

If a custom function registered via `registerFunction()` calls `getContextValue('x', [])` internally and `'x'` is absent, the resulting `UnknownVariableException` **passes through `evaluateSafe` unchanged**. It is not converted into an entry in `missingVars`.

**Reason**: the library cannot know whether the variable looked up inside the function was conceptually part of the expression — it may be an implementation detail of the function. Silently transforming this into "missing" could hide a real bug.

Custom function authors who want to participate in the "missing" protocol should either use `getContextValueOrDefault()`, or catch the exception and handle it themselves.

### No safe mode for `evaluateBoolean` / `evaluateNumeric`

There is no `evaluateSafeBoolean` or `evaluateSafeNumeric` method. To validate the result type in safe mode, do so after the fact:

```php
$result = $eval->evaluateSafe('a > 0', $context);
if ($result->success && is_bool($result->value)) {
    // ...
}
```

## Known limitations

### No "missing" for unknown functions

If the expression calls an unregistered function (e.g. `unknownFn(a)`), an `EvaluatorException` is thrown — not reported as "missing". This is consistent: an unknown function is an expression (or configuration) problem, not a data problem.

### No granularity by partial path

If `cart.total` is missing, the list reports `'cart.total'` (the requested path), not `'cart'` (the absent parent). This can be counterintuitive if the context doesn't even contain the `cart` key, but the path referenced in the expression takes precedence. Consistent with `UnknownVariableException` in strict mode.
