# Static AST Analysis

## Overview

Static analysis allows inspecting an expression **without evaluating it**: extracting the list of referenced variables, the list of called functions, or simply validating that it is syntactically correct. No context is needed — it is purely structural introspection.

Typical use cases:
- **Back-office**: validate that a user rule only references allowed variables/functions before storing it in a database
- **Auto-generated documentation**: list the variables expected by each rule
- **Coverage checking**: ensure all variables in a context are actually used by the rules, or vice versa

## API

```php
public function extractVariables(string $expression): array  // string[]
public function extractFunctions(string $expression): array  // string[]
public function validate(string $expression): void
```

## Behaviors

### `extractVariables(string $expression): string[]`

Returns all variable paths referenced in the expression, deduplicated and sorted alphabetically.

The traversal is **exhaustive**: all branches of the AST are visited, regardless of short-circuits or ternary branch selection at evaluation time. This is expected: static analysis does not know runtime values.

**Examples**:
```php
$eval->extractVariables('a > 0 AND b.c = d ?? e');
// ['a', 'b.c', 'd', 'e']

$eval->extractVariables('cart.total + cart.shipping');
// ['cart.shipping', 'cart.total']  ← sorted, deduplicated

$eval->extractVariables('a > 0 ? b : c');
// ['a', 'b', 'c']  ← BOTH branches are visited, even if evaluation only takes one

$eval->extractVariables('round(cart.total, precision)');
// ['cart.total', 'precision']  ← descends into function arguments

$eval->extractVariables('x IN list');
// ['list', 'x']

$eval->extractVariables('1 + 2');
// []  ← no variables
```

**Exceptions thrown**:
- `SyntaxErrorException` — the expression is malformed (parsing must succeed before analysis can proceed)

### `extractFunctions(string $expression): string[]`

Returns all function names called in the expression, deduplicated and sorted.

```php
$eval->extractFunctions('round(a, 2) > min(b, c)');
// ['min', 'round']

$eval->extractFunctions('upper(name) = "ALICE"');
// ['upper']

$eval->extractFunctions('1 + 2');
// []
```

Typically useful before evaluation: you can compare the result against `getFunctions()` (the list of registered functions) to reject an expression calling an unknown function **before** attempting to evaluate it.

```php
$used      = $eval->extractFunctions($userRule);
$available = $eval->getFunctions();
$unknown   = array_diff($used, $available);
if (!empty($unknown)) {
    throw new \InvalidArgumentException('Unknown functions: ' . implode(', ', $unknown));
}
```

### `validate(string $expression): void`

Attempts to parse the expression. Returns nothing on success, throws an exception otherwise.

Functionally equivalent to `getAst($expression)` but without the return value — the intent is expressed more clearly when you only want to validate.

```php
try {
    $eval->validate($userRule);
    // OK, syntax is valid
} catch (SyntaxErrorException $e) {
    // Syntax error at $e->position
}
```

**Exceptions thrown**:
- `SyntaxErrorException` — the expression contains a lex or parse error. The `$position` property indicates the offset (in bytes) in the original string.

⚠️ `validate()` only checks **syntax**. It does not verify that called functions exist (use `extractFunctions()` + `getFunctions()` for that), nor that referenced variables will be present in the context (use `extractVariables()` + comparison against the expected context).

## Design decisions

### No evaluation, no context

These three methods **never** require a context. This is intentional: their role is to answer structural questions about the expression, independent of the data.

Important consequence: `extractVariables('a ? b : c')` returns `['a', 'b', 'c']` even though at evaluation time only branch `b` (or `c`) will be visited. Static analysis cannot predict what will actually be used.

### Alphabetical sorting

The lists returned by `extractVariables()` and `extractFunctions()` are sorted via `sort()`. This is consistent and simplifies equality testing, but loses the order of appearance in the expression. If that order ever becomes useful, the API would need to be duplicated (or return a richer object).

### AST cache reused

These methods go through `getAst()` internally — they benefit from the LRU cache. If you validate and then evaluate the same expression, parsing happens only once. See `ast-management.md`.

## Known limitations

### No inspection of literals or operators

There is no equivalent `extractLiterals()` or `extractOperators()`. For more advanced analysis (e.g. "what is the maximum value compared in this rule?"), write your own walker on the AST exposed by `getAst()`.

The AST is intentionally public (interfaces and `final` classes but accessible) to allow these custom analyses.

### No detection of dynamic paths

Variable paths are extracted literally. An expression like `prefix.field` is treated as a single path `prefix.field`, with no attempt at dynamic resolution. This is consistent with the rest of the library: paths are syntactic constants, not expressions.
