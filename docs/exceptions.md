# Exceptions and Errors

## Overview

The library uses a consistent exception hierarchy, all derived from `EvaluatorException` (itself derived from `\RuntimeException`). This design allows:
- Catching all library errors with a single `catch (EvaluatorException $e)`
- Catching a specific error type (`UnknownVariableException`) when you want to handle it explicitly
- Distinguishing syntax errors (compile-time) from evaluation errors (runtime)

No public method propagates a raw PHP exception (`\TypeError`, `\DivisionByZeroError`...). Any exception thrown by user code (custom functions) is re-wrapped as `TypeErrorException`.

## Hierarchy

```
\RuntimeException
  └── EvaluatorException                  (base)
        ├── SyntaxErrorException          (lex/parse)
        ├── TypeErrorException            (runtime typing)
        ├── UnknownVariableException      (missing variable)
        └── CircularContextException      (circular context in describe)
```

All classes live in the `Ols\PhpRuler\Exception` namespace.

## `EvaluatorException`

**Base class** for all library exceptions. Not thrown directly in practice (always via a subclass), but useful for a catch-all.

Thrown directly only for corrupted AST or depth-exceeded cases (where none of the more precise subclasses apply):
- Evaluation depth exceeded (200 levels)
- Unknown AST node (corruption or unimplemented extension)
- Unknown unary/binary operator (same)
- Unregistered function

```php
try {
    $eval->evaluate($expression, $context);
} catch (\Ols\PhpRuler\Exception\EvaluatorException $e) {
    // Catches everything: syntax, type, missing variable, corrupted AST...
}
```

## `SyntaxErrorException`

Thrown at the **lex or parse phase** — that is, **before any evaluation**.

**Distinguishing feature**: carries a public `$position` property (int, in bytes) indicating the offset in the original string.

```php
final class SyntaxErrorException extends EvaluatorException
{
    public function __construct(string $message, public readonly int $position);
}
```

Typical cases:
- Unknown token (`'@'`, `'#'`, etc.)
- Unclosed parenthesis, unbalanced bracket
- Missing operand after an operator (`'a + '`)
- Integer literal exceeding `PHP_INT_MAX` (or `PHP_INT_MIN` not preceded by a unary `-`)
- Float literal exceeding `PHP_FLOAT_MAX` (becomes `INF` on cast, immediately rejected)
- Trailing comma in a list (`[1, 2,]`)
- Empty list on the right of `IN` (`x IN []`)
- Scalar on the right of `IN` (`x IN 5`)
- Surplus token at the end of the expression (`'a + b 5'`)

**Example**:
```php
try {
    $eval->evaluate('a + ', $context);
} catch (\Ols\PhpRuler\Exception\SyntaxErrorException $e) {
    echo $e->getMessage();   // 'Unexpected token "" at position 4'
    echo $e->position;       // 4
}
```

**How to handle**:
- In a back-office: surface the message + position to the user to help them correct their rule
- In code: indicates a bug in the provided string, not in the context. Re-providing the same context will not fix the problem.

### `validate()` only throws this type

`ExpressionEvaluator::validate($expression)` throws only `SyntaxErrorException` (or no exception at all). No semantic validation (variables, functions, types) at this level.

## `TypeErrorException`

Thrown **at evaluation time**, when an operation encounters an incompatible type.

Covered cases (non-exhaustive list):
- Logical AND/OR/NOT/ternary operator with a non-bool operand (`5 AND true`)
- Arithmetic with a non-numeric operand (`null + 1`, `'5' * 2`)
- Comparison of incompatible types (`'a' > 5`)
- Direct equality on arrays (`[1,2] = [1,2]`) — use `IN`
- NaN or INF appearing in the pipeline
- Division by zero (`1 / 0`)
- Integer overflow (`PHP_INT_MAX + 1` would downcast to float, rejected)
- `evaluateBoolean()` with a non-bool result
- `evaluateNumeric()` with a non-numeric result
- Function called with wrong arity
- Custom function throwing a non-library `\Throwable` (wrapped with `previous`)
- Unsupported type in the context (object, closure, resource...)
- Invalid date format passed to date functions
- Built-in function argument constraints (e.g. `clamp` with `min > max`)

**Shared characteristic**: this is almost always a **logical typing problem** in the expression or the data. The caller must fix the expression or clean up its context.

```php
try {
    $eval->evaluate("'hello' AND true", []);
} catch (\Ols\PhpRuler\Exception\TypeErrorException $e) {
    echo $e->getMessage();
    // 'Operator "AND": expected boolean, string given. Use an explicit comparison...'
}
```

### Chaining via `previous`

When a custom function throws a non-library `\Throwable`, the original exception is preserved in `$e->getPrevious()`:

```php
$eval->registerFunction('boom', fn() => throw new \RuntimeException('boom'));
try {
    $eval->evaluate('boom()', []);
} catch (\Ols\PhpRuler\Exception\TypeErrorException $e) {
    echo $e->getMessage();              // 'Error in function "boom": boom'
    echo $e->getPrevious()->getMessage(); // 'boom'  ← the original
}
```

This is useful for debugging: the root cause can be traced without losing the wrapped message for the API consumer.

## `UnknownVariableException`

Thrown when a referenced variable does not exist in the context.

**Structured property**: exposes `public readonly string $variablePath` — the exact path as requested (as written in the expression). Useful for logging or back-office display without having to parse the text message.

**Message**: indicates the requested path. If resolution failed after traversing at least one segment, indicates the failing segment:

- `'Unknown variable: "customer"'` — absent root
- `'Unknown variable: "cart.shipping" (failed at "cart.shipping")'` — `cart` exists but `cart.shipping` does not

```php
try {
    $eval->evaluate('cart.shipping > 0', ['cart' => ['total' => 100]]);
} catch (\Ols\PhpRuler\Exception\UnknownVariableException $e) {
    echo $e->getMessage();
    // 'Unknown variable: "cart.shipping" (failed at "cart.shipping")'
    echo $e->variablePath;  // 'cart.shipping'
}
```

### Caught and collected by safe / explain modes

- `evaluateSafe()`: catches these exceptions and collects the paths in `SafeResult::missingVars`
- `ExpressionExplainer::explain()`: captures them as `ExplainStatus::MISSING` with the message in `detail`

All other exceptions (`TypeErrorException`, `EvaluatorException`...) pass through both modes **unchanged**.

**Special case**: an `UnknownVariableException` thrown **from inside a custom function** body (e.g. if the function calls `getContextValue('x', [])`) is not converted to "missing" by safe mode — see `functions.md` and `evaluate-safe.md`.

## `CircularContextException`

Specific to `describeContext()` (and `ContextResolver::describe`). Thrown when the context structure exceeds `MAX_DEPTH = 64` nesting levels.

In practice, this almost always signals a **circular reference**:

```php
$ctx = ['data' => 'x'];
$ctx['self'] = &$ctx;

$eval->describeContext($ctx);
// CircularContextException: 'Context nesting exceeds 64 levels at "self.self.self..."'
```

**Not thrown by `evaluate()` or `getContextValue()`**: these methods perform a descent bounded by the requested path (e.g. 3 segments for `a.b.c`), not a full exploration. They are not exposed to cycle risk.

## Propagation policy

### Which exceptions come out of which method

| Method | Possible exceptions |
|---|---|
| `evaluate(string)` | All: `SyntaxErrorException`, `TypeErrorException`, `UnknownVariableException`, `EvaluatorException` |
| `evaluateAst(Node)` | No `SyntaxErrorException` (already parsed); others possible |
| `evaluateBoolean()` / `evaluateNumeric()` | Everything `evaluate()` can throw + `TypeErrorException` if final type is unexpected |
| `evaluateSafe(string)` | `SyntaxErrorException`, `TypeErrorException`, `EvaluatorException`. **Not** `UnknownVariableException` (collected). |
| `evaluateSafeAst(Node)` | Same as `evaluateSafe()` without `SyntaxErrorException`. |
| `validate()` | `SyntaxErrorException` only. |
| `getAst()` | `SyntaxErrorException` only (no evaluation). |
| `exportAst()` | Same as `getAst()`. |
| `importAst()` | `\InvalidArgumentException` (corrupted struct / cycle / depth). No `SyntaxError` (nothing to parse). |
| `getContextValue()` | `UnknownVariableException` only. |
| `getContextValueOrDefault()` | None (returns the default). |
| `hasContextValue()` | None (returns `false`). |
| `describeContext()` | `CircularContextException` possible. |
| `registerFunction()` | None (the signature is introspected but not called). |
| `getFunctions()` | None. |
| `callFunction()` | `EvaluatorException` (unknown function), `TypeErrorException` (arity or internal type). |
| `extractVariables()`, `extractFunctions()` | `SyntaxErrorException` only. |
| `ExpressionExplainer::explain()` | `SyntaxErrorException` or structural `EvaluatorException` only. **Not** missing/type evaluation errors (they become `MISSING`/`ERROR` nodes). |
| `AliasResolver::add()` | `\InvalidArgumentException` (alias validation). |
| `AliasResolver::humanToExpression()` / `expressionToHuman()` | `\InvalidArgumentException` on invalid UTF-8. |

### Global guarantees

1. **No raw PHP exception exits public methods** (except `\InvalidArgumentException` documented in `importAst()` and `AliasResolver`, and `\LogicException` documented in `SafeResult::getValue()`).

2. **Library exceptions pass through custom functions unchanged**: if a custom function calls `evaluate()` internally and it throws `UnknownVariableException`, it propagates without wrapping.

3. **Non-library exceptions thrown by custom functions are wrapped as `TypeErrorException`** with `previous` pointing to the original.

4. **Recursion counters are reset on exception**: a failed call does not leave the evaluator in an inconsistent state.

## Exceptions outside the hierarchy

Three exceptions do not derive from `EvaluatorException`:

### `\InvalidArgumentException`

Thrown by:
- `importAst()` when the serialized string is corrupted, contains an unauthorized class, a cycle, or exceeds the depth limit
- `AliasResolver::add()` when the alias violates a validation rule
- `AliasResolver::humanToExpression()` / `expressionToHuman()` on invalid UTF-8

Rationale: these are **API usage errors** (invalid parameters on the caller side), not evaluation errors per se. Consistent with the standard PHP convention.

### `\LogicException`

Thrown by:
- `SafeResult::getValue()` when `success === false`

Rationale: this is a **programming error on the caller side** (forgetting to check `success` before accessing `value`). Not an unpredictable runtime condition — hence `LogicException` rather than a runtime exception.

## Design decisions

### Base `\RuntimeException`

`EvaluatorException` derives from `\RuntimeException`, not `\Exception` directly. PHP convention: exceptions related to runtime state (as opposed to programming errors, `\LogicException`) derive from `\RuntimeException`.

### No numeric error codes

No exception uses the `$code` constructor parameter of `\Exception`. The text message is the source of information. If an external integration needs to distinguish programmatically, it can use the **exception type** (`instanceof`) — which is more type-safe and refactor-friendly than magic codes.

### Medium granularity

The hierarchy has 4 subclasses (beyond the base). A balanced choice:
- Granular enough to distinguish "data" cases (`UnknownVariableException`), "logic" cases (`TypeErrorException`), "syntax" cases (`SyntaxErrorException`), "context" cases (`CircularContextException`)
- Not too granular to avoid an explosion of catch clauses (`AndOperatorException`, `OrOperatorException`...). When multiple cases share the same intent (typing problem), a single exception suffices — the text message gives the specifics.

### Self-contained messages

Messages include the context necessary for the user:
- Variable path (`UnknownVariableException`)
- Position in the expression (`SyntaxErrorException`)
- Operator name and observed types (`TypeErrorException`)
- Suggested action where relevant (`Use an explicit comparison...`)

This allows a back-office to display the raw message to the user without additional translation.

### Uniform policy for custom functions

Any error in a custom function:
- If it belongs to the library hierarchy → propagated as-is
- Otherwise → wrapped in `TypeErrorException` with the prefix `Error in function "..."` and `previous` populated

No raw PHP exception leaks. The API contract is uniform.

### No "recovery" exception

The library does not define "recoverable" vs "non-recoverable" exceptions. It is up to the caller to catch what it knows how to handle. The type hierarchy is the primary discrimination tool.

## Known limitations

### No message localization

Messages are in **English**, intentionally. No built-in i18n mechanism. This choice is final for the library itself.

The recommended extension point for a back-office that needs its own messages: use the **exception type** (`instanceof`) and the **structured properties** (e.g. `$variablePath` on `UnknownVariableException`, `$position` on `SyntaxErrorException`) to compose a localized message on the caller side, without parsing the free-text message.

### Partially structured details

The most useful exceptions already expose typed public properties: `UnknownVariableException::$variablePath` and `SyntaxErrorException::$position`. No need to parse the message text for these two cases.

However, other exceptions (`TypeErrorException` in particular) remain free-text: programmatically distinguishing "division by zero" from "incompatible type" still requires pattern-matching on the message.

### `TypeErrorException` is somewhat of a catch-all

The type covers many cases (arithmetic, comparison, custom functions...). A caller wanting to distinguish "division by zero" from "incompatible type" must pattern-match the message. Current behavior is acceptable but could be improved.
