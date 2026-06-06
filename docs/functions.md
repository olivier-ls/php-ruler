# Functions

## Overview

The library exposes a function call system within expressions. Two categories:
- **Built-in functions**: automatically registered by the evaluator (arithmetic, dates, strings, lists, type casting...)
- **Custom functions**: dynamically registered via `registerFunction()` at initialization

All functions share the same call mechanics and the same error-handling policy: from an expression's perspective, a built-in and a custom function are indistinguishable.

## API

```php
public function registerFunction(string $name, callable $fn): self
public function getFunctions(): array          // string[], sorted
public function callFunction(string $name, array $resolvedArgs): mixed
```

## General behaviors

### `registerFunction(string $name, callable $fn): self`

Registers a function under the given name. **Silently overwrites** an existing function of the same name, whether built-in or previously custom. This is intentional: you can specialize `round()` or `today()` for a project without touching the library code.

```php
$eval->registerFunction('greet', fn(string $name): string => "Hello, $name!");
$eval->evaluate('greet(user.name)', ['user' => ['name' => 'Alice']]);
// → "Hello, Alice!"

// Overriding a built-in:
$eval->registerFunction('today', fn(): string => '2026-01-01');  // for tests
```

**Arity captured at registration**: via reflection, the library stores `min` (required parameters) and `max` (total parameters, or `PHP_INT_MAX` if variadic). This arity is validated on every call — no runtime reflection.

### `getFunctions(): string[]`

Returns the list of registered function names (built-in + custom), sorted alphabetically.

```php
$eval->getFunctions();
// ['abs', 'avg', 'bool', 'ceil', 'clamp', 'coalesce', 'concat', 'contains', ...]
```

Useful for validating user expressions in a back-office (before evaluation), or for editor autocompletion.

### `callFunction(string $name, array $resolvedArgs): mixed`

Calls a function by name with **already-resolved** arguments (PHP values, not AST nodes).

This method is exposed primarily for the `ExpressionExplainer`: it avoids double evaluation of arguments when intermediate values have already been traced. For regular usage, the `FunctionNode` evaluation handles this internally.

**Validations performed**:
1. The function exists (`EvaluatorException` otherwise)
2. The argument count is within bounds (`TypeErrorException` otherwise)
3. If the function body throws, the exception is handled per the policy below

### Function exception policy

When the body of a function (built-in or custom) throws an exception, handling depends on the type:

| Exception type thrown | Handling |
|---|---|
| `EvaluatorException` and descendants (`TypeErrorException`, `UnknownVariableException`, etc.) | Propagated **unchanged** |
| Any other `\Throwable` (`RuntimeException`, `LogicException`, `Error`, native `TypeError`, etc.) | Wrapped in `TypeErrorException` with the original as `previous` |

**Rationale**:
- Library exceptions pass through unchanged, allowing a custom function to cleanly delegate to `evaluate()` or `getContextValue()` internally
- Any raw PHP exception is wrapped to guarantee a consistent error API — `evaluate()` never leaks a native `TypeError` or similar

**Practical consequence**: user code inside a custom function can throw anything; the caller of `evaluate()` will only ever deal with library exceptions.

### Arity validation

PHP silently ignores extra arguments on non-variadic closures. The library **rejects** this behavior: passing too many arguments throws a clear `TypeErrorException`.

```php
$eval->evaluate('round(1, 2, 3)', []);
// TypeErrorException: Function "round" expects between 1 and 2 arguments, 3 given
```

The message includes the bounds based on the case:
- `exactly N` if min == max
- `at least N` if variadic (max == PHP_INT_MAX)
- `between N and M` otherwise

## Built-in catalogue

Built-ins are registered at `Evaluator` construction time. They can be overridden via `registerFunction()`.

### Type casting

#### `int(val)` → `int`

Converts to integer. **Truncates toward zero** for floats (behavior of PHP's `(int)` cast):
- `int(3.7) → 3`, `int(3.5) → 3`, `int(-3.7) → -3`, `int(-3.5) → -3`
- Accepted: `int` (passthrough), `float` (truncation), integer `string` (regex `/^-?[0-9]+$/`)
- Rejected: float string (`'3.7'`), `bool`, `null`, other

⚠️ If you want rounding, use `round()`. For floor/ceil, use `floor()` / `ceil()`.

#### `float(val)` → `float`

Converts to float. Accepts `int`, `float`, and any `is_numeric()` string (so `'3.14'`, `'1e5'`, etc.). Rejects the rest.

#### `bool(val)` → `bool`

**Strict** — more restrictive than PHP's native `(bool)` cast. Accepts only:
- `bool` (passthrough)
- `int` strictly 0 or 1
- `float` strictly 0.0 or 1.0
- `string` `'0'`, `'1'`, `'true'`, `'false'` (the last is case-insensitive)

Everything else (2, [], null, other string) → `TypeErrorException`.

#### `str(val)` → `string`

Converts to string. For floats, formats **without scientific notation**:
- Adapted to magnitude (14 decimals below 1, adjusted above to total 15 significant digits)
- Trailing zeros removed
- Rejects NaN, INF, |val| >= 1e15, |val| < 1e-10 (see `formatFloatForString()`)

Examples: `str(1.0) → '1'`, `str(1.50) → '1.5'`, `str(0.000001) → '0.000001'`

### Arithmetic

#### `round(val, precision = 0)` → `float`

Rounds with `precision` decimal places. Bound: `0 <= precision <= 14` (aligned with `PHP_FLOAT_DIG = 15` significant digits — beyond 14, `round()` no longer has physical meaning).

#### `floor(val)` → `float`, `ceil(val)` → `float`

Round down / round up.

#### `abs(val)` → `int|float`

Absolute value. Type preserved.

#### `min(a, b)` → `int|float`, `max(a, b)` → `int|float`

⚠️ **Exactly 2 arguments**. For minimum/maximum of a list, use `min_of()` / `max_of()`. This is intentional: `min(a, b, c)` could be confusing (fixed variadic or list?), so the explicit form is enforced.

#### `pow(base, exp)` → `int|float`

Exponentiation. Returns `int` if both operands are `int` and the result fits in `PHP_INT_MAX`, otherwise `float`.

**Specific guards**:
- Negative base with non-integer exponent → rejected (result would be NaN)
- Zero base with negative exponent → rejected (division by zero)
- Result is NaN or INF → rejected with a clear message

This is the **only** function where NaN/INF are caught **inside the function** (rather than only at the downstream operator). Rationale: `pow(10, 1000)` is the most common overflow case; the message from `pow()` is more actionable than "operator '+' value is INF".

#### `sqrt(val)` → `float`

Square root. Rejects negative values.

#### `clamp(val, min, max)` → `int|float`

Clamps `val` to `[min, max]`. Rejects if `min > max`.

#### `is_finite(val)` → `bool`

**Escape hatch to inspect NaN/INF**: the only way to test these values from within an expression, since every arithmetic or comparison operator rejects them.

```php
'is_finite(x)'  // true if x is a finite number, false if NaN/INF, TypeError if not a number
```

### Strings

| Function | Description |
|---|---|
| `length(val)` | Length in UTF-8 characters (string) or number of elements (array) |
| `count(list)` | Number of elements in an array (equivalent of `length()` for lists only — `TypeError` if not an array). Makes the intent explicit when working with lists. |
| `upper(s)` | Uppercase (`mb_strtoupper`) |
| `lower(s)` | Lowercase (`mb_strtolower`) |
| `trim(s)` | Standard PHP trim |
| `contains(haystack, needle)` | `str_contains` |
| `startsWith(haystack, needle)` | `str_starts_with` |
| `endsWith(haystack, needle)` | `str_ends_with` |
| `substr(s, start, length?)` | `mb_substr` |
| `concat(a, b, ...)` | Concatenates, accepts string/int/float (float formatted like `str()`) |
| `replace(subject, search, replace)` | `str_replace` |

### Lists (aggregates)

| Function | Description | Empty list |
|---|---|---|
| `sum(list)` | Sum | `0` |
| `avg(list)` | Average | `TypeErrorException` |
| `min_of(list)` | Minimum | `TypeErrorException` |
| `max_of(list)` | Maximum | `TypeErrorException` |

All validate that elements are numeric; reject the offending element with its index.

### Other

#### `coalesce(a, b, c, ...)` → `mixed`

Returns the first non-`null` argument. N-ary complement to the `??` operator.

```php
'coalesce(a, b, c, 0)'  // 0 if a, b, and c are all null
```

### Dates

Supported formats: `Y-m-d`, `Y-m-d H:i`, `Y-m-d H:i:s`, `Y-m-d\TH:i`, `Y-m-d\TH:i:s` (ISO 8601 with `T` separator, common in JSON/REST APIs).

| Function | Description |
|---|---|
| `today()` | Current date in `Y-m-d` format |
| `now()` | Current date+time in `Y-m-d H:i:s` format |
| `year(date)` | Year (int) |
| `month(date)` | Month (int) |
| `day(date)` | Day (int) |
| `hour(date)` | Hour (int) |
| `minute(date)` | Minute (int) |
| `dateDiff(date1, date2)` | Difference in whole days, negative if `date1 > date2` |
| `dateAdd(date, n, unit)` | Adds `n` units to the date. `unit` ∈ `{day, month, year, hour, minute}`. `n` may be negative. |

**Format preserved by `dateAdd()`**: `Y-m-d` stays `Y-m-d`, `Y-m-d H:i` stays `Y-m-d H:i`, `Y-m-d H:i:s` stays `Y-m-d H:i:s`, and ISO 8601 variants with `T` stay with `T`.

**Timezone for `today()` and `now()`**: use the PHP server timezone (`date_default_timezone_get()`). This behavior is intentional and documented. For a specific timezone, register a custom function via `registerFunction()` — that is the recommended extension point.

**Month/year overflow**: no "snap to end of month":
```
dateAdd('2026-01-31',  1, 'month') → '2026-03-03'   (not '2026-02-28')
dateAdd('2024-02-29',  1, 'year')  → '2025-03-01'   (not '2025-02-28')
dateAdd('2026-03-31', -1, 'month') → '2026-03-03'   (not '2026-02-28')
```

If you need "end of month" logic, implement it at the expression level (custom function).

## Design decisions

### All built-ins are overridable

No "protected function": a project that wants to redefine `today()` (for tests) or `round()` (for specific business rules) can do so without workarounds.

**Known consequence**: a `registerFunction('round', ...)` invalidates the assumptions of the rest of the project if multiple codepaths share the same `ExpressionEvaluator` instance. Use with care.

### `min` / `max` with exactly 2 arguments

Intentional for clarity: `min(a, b)` operates on two values, `min_of([a, b, c])` operates on a list. No variadic ambiguity.

### NaN / INF rejected as early as possible

The general policy is: NaN/INF never transit **through an operator** during evaluation. As a result:
- At every arithmetic operator, comparison, equality (and unary `-`) → `TypeErrorException`
- In `pow()` specifically (rejected internally for a clear message)
- **However**, variable resolution and function return values do **not** reject: a NaN/INF value can transit until `is_finite()` without entering an operator (which is precisely what makes `is_finite()` usable).

The only inspection escape hatch: `is_finite()`, which inspects without entering an operator.

### Uniform `TypeErrorException` policy

All type rejections (wrong arity, incorrect type, out-of-bound value, NaN/INF, etc.) throw `TypeErrorException` — not `InvalidArgumentException` or other. This enables a clean distinction for the caller between:
- `SyntaxErrorException`: syntax problem (compile-time)
- `UnknownVariableException`: context problem (runtime, data)
- `TypeErrorException`: typing problem (runtime, code/logic)
- `EvaluatorException`: structural problem (corrupted AST, depth, unknown function)

### Arity captured once

Reflection (`ReflectionFunction`) is used only once, at registration time, to capture `min` and `max`. No runtime reflection on each call — the overhead is concentrated in `registerFunction()`.

## Known limitations

### No signature introspection

`getFunctions()` returns only names. No API to retrieve the signature (parameter types, argument names, description...). For contextual help in an editor, this would need to be added — conceptually feasible via reflection, but no API is exposed at this time.

### No built-in versioning

A library update may change the behavior of a built-in (e.g. if an edge case in `dateAdd` is fixed). No per-function version mechanism.

### No namespacing

Function names are flat in a single dictionary. No `math.round` vs `string.length`. A `length` function registered by the user overwrites the built-in.
