# php-ruler

Pure PHP expression evaluation library (no extensions required). No dependencies. PHP 8.2+.

## In a nutshell

Pass an expression as a string (`"cart.total > 100 AND customer.vip = true"`), a context (a PHP array of variables), and get back the evaluated result — in a strict, predictable, type-safe way.

## Installation

```bash
composer require expreval/expreval
```

## Quick start

```php
use Ols\PhpRuler\ExpressionEvaluator;

$eval = new ExpressionEvaluator();

// Basic evaluation
$eval->evaluate('cart.total > 100', ['cart' => ['total' => 150]]);   // true
$eval->evaluate('round(cart.total * 1.2, 2)', ['cart' => ['total' => 100]]);  // 120.0
$eval->evaluate('upper(customer.name)', ['customer' => ['name' => 'alice']]);  // 'ALICE'

// Enforce a return type
$eval->evaluateBoolean('a > 0 AND b < 10', ['a' => 5, 'b' => 7]);   // true (guaranteed bool)
$eval->evaluateNumeric('price * qty', ['price' => 9.99, 'qty' => 3]); // 29.97 (guaranteed float)
```

## Language

The language is intentionally close to PHP: same precedences, same operator semantics. A PHP developer should feel right at home.

```
cart.total > 100
customer.vip = true AND cart.total > 50
cart.country IN ['FR', 'BE', 'CH']
customer.score ?? 0 > 50       ← watch out for precedence, see "Common pitfalls"
(customer.score ?? 0) > 50     ← correct form
today() > '2026-01-01'
round(total * 0.9, 2)
```

Full reference: [language-reference.md](language-reference.md).

## Strictly typed, no coercion

```php
// In native PHP: "false" AND true → true (the string "false" is truthy)
// In php-ruler:  TypeErrorException — "false" is not a bool

// In native PHP: null + 1 → 1
// In php-ruler:  TypeErrorException — null is not a number

// In native PHP: '5' == 5 → true
// In php-ruler:  TypeErrorException — string and int cannot be compared with =
```

The only tolerance is `int` vs `float` on equality (`5 = 5.0` → `true`) — which is useful in practice.

## `evaluateNumeric()` always returns `float`

Even for an integer calculation:

```php
$eval->evaluateNumeric('5 + 3', []);  // 8.0, not 8
```

This is intentional: a uniform output type for business calculations (prices, rates). Cast to `int` afterwards if needed.

## Evaluation modes

### Strict mode (default)

Any anomaly throws an exception.

```php
$eval->evaluate('a > 0', []);  // UnknownVariableException — 'a' is missing
```

### Safe mode

Collects missing variables instead of throwing.

```php
$result = $eval->evaluateSafe('a > 0 AND b < 10', ['a' => 5]);
$result->success;      // false — 'b' was missing
$result->missingVars;  // ['b']
$result->getValueOr(false);  // false (fallback)
```

See [evaluate-safe.md](evaluate-safe.md).

### Explainer

Node-by-node diagnostics — why a rule passed or failed.

```php
$explainer = new \Ols\PhpRuler\Explainer\ExpressionExplainer($eval);
$result = $explainer->explain('a > 0 AND b < 10', ['a' => 5, 'b' => 20]);

$result->passed;           // false
$result->failures();       // [ExplainNode 'b < 10' — passed=false, leftValue=20, rightValue=10]
$result->successes();      // [ExplainNode 'a > 0' — passed=true]
$result->unresolved();     // combines missing() + errors() — everything that blocked evaluation
```

⚠️ **Do not use side-effect functions with the Explainer** — each function may be called twice per occurrence in a compound expression. See [explainer.md](explainer.md).

## Built-in functions

Arithmetic, strings, lists, dates, type casting. All overridable via `registerFunction()`.

```php
// Casting
int(3.7)          // 3 (truncates toward zero)
float('3.14')     // 3.14
bool('true')      // true
str(1.5)          // '1.5'

// Math
round(3.14159, 2) // 3.14  (max precision: 14)
abs(-5)           // 5
clamp(x, 0, 100)  // clamps x to [0, 100]
pow(2, 10)        // 1024

// Strings
upper(name)              // uppercase
contains(s, 'search')    // bool
concat(first, ' ', last) // concatenation

// Lists
count(tags)       // number of elements (array-only alias of length())
length(tags)      // same, also works on strings
sum(amounts)      // sum
avg(scores)       // average

// Dates (formats: Y-m-d, Y-m-d H:i, Y-m-d H:i:s, and ISO 8601 variants with T)
today()                         // '2026-01-15'
dateDiff(today(), created_at)   // days since creation
dateAdd(expiry, 30, 'day')      // add 30 days
```

Full reference: [functions.md](functions.md).

## Custom functions

```php
$eval->registerFunction('discount', function(float $price, float $pct): float {
    return round($price * (1 - $pct / 100), 2);
});

$eval->evaluate("discount(cart.total, 10)", ['cart' => ['total' => 100.0]]);  // 90.0
```

## Cache and AST

The LRU cache is automatic (default: 500 entries). Configurable via the constructor:

```php
$eval = new ExpressionEvaluator(cacheMaxSize: 2000);  // larger cache
$eval = new ExpressionEvaluator(cacheMaxSize: 0);     // cache disabled
```

To store a compiled AST in a database (avoids re-parsing on next startup):

```php
// Export: versioned JSON envelope {"v":1,"ast":"..."}
$stored = $eval->exportAst('cart.total > threshold');

// Import: validates version + structure + cycles
$ast  = $eval->importAst($stored);
$eval->evaluateAst($ast, $context);
```

⚠️ `importAst()` checks the format version. If the library is updated with a structural change, stored payloads must be regenerated.

See [ast-management.md](ast-management.md).

## Architecture: `Node` and traversal

`Node` is an **empty interface** — intentionally. No visitor pattern is imposed: traversal is done via `instanceof` against concrete classes (`BinaryNode`, `UnaryNode`, `LiteralNode`, `VariableNode`, `InNode`, `FunctionNode`, `TernaryNode`).

Why? A formal visitor would have required one method per node type in the interface. Any new `Node` class would have broken third-party implementations. For a library whose AST may still evolve, that is a disproportionate constraint.

For custom traversal, use `getAst()` and inspect via `instanceof`:

```php
$ast = $eval->getAst('a > 0 AND b < 10');
// Walk $ast using instanceof BinaryNode, VariableNode, etc.
```

`extractVariables()` and `extractFunctions()` are ready-to-use traversal utilities.

## Common pitfalls

### `??` precedence — lower than comparisons

```php
// ❌ Not what you'd expect
$eval->evaluate('a ?? 0 > 100', ['a' => null]);
// → null ?? (0 > 100) → null ?? false → false

// ✅ Correct form
$eval->evaluate('(a ?? 0) > 100', ['a' => null]);
// → (null ?? 0) > 100 → 0 > 100 → false
```

### `NOT a ?? b` — NOT binds very tightly

```php
// NOT has very high precedence (aligned with PHP !)
'NOT a ?? b'    →  '(NOT a) ?? b'    // NOT NOT (a ?? b)
'NOT (a ?? b)'  →  correct form
```

## Exceptions

All derive from `Ols\PhpRuler\Exception\EvaluatorException` (`\RuntimeException`).

```
EvaluatorException
  ├── SyntaxErrorException     — lex/parse (property $position)
  ├── TypeErrorException       — runtime type error
  ├── UnknownVariableException — missing variable (property $variablePath)
  └── CircularContextException — circular context (describeContext)
```

Exception messages are in **English**, intentionally. The extension point for a localized back-office: use the exception type and its structured properties (`$variablePath`, `$position`) to compose localized messages on the caller side.

See [exceptions.md](exceptions.md).

## Full documentation

| File | Contents |
|---|---|
| [language-reference.md](language-reference.md) | Syntax, operators, precedences, pitfalls |
| [evaluate.md](evaluate.md) | Strict mode, API, behaviors |
| [evaluate-safe.md](evaluate-safe.md) | Safe mode, SafeResult, short-circuits |
| [explainer.md](explainer.md) | Node-by-node diagnostics |
| [functions.md](functions.md) | Built-in catalogue, custom functions |
| [exceptions.md](exceptions.md) | Exception hierarchy, propagation policy |
| [ast-management.md](ast-management.md) | Cache, export/import, limits and constants |
| [context.md](context.md) | Variable resolution, dot-notation |
| [alias-resolver.md](alias-resolver.md) | Path ↔ human alias translation |
| [static-analysis.md](static-analysis.md) | extractVariables, extractFunctions, validate |
