# Language Reference

## Overview

This document describes the **syntax** accepted by the library: what you can write in an expression and what it means. It is the reference for anyone writing expressions, whether a developer integrating the library or an end user in a back-office.

The language is intentionally **close to PHP** (operators, precedences, literal semantics) while being **strict** (no silent coercion, no accepted overflow, etc.). A PHP developer's intuition should be correct by default.

## Syntax overview

```
expression := ternary
ternary    := or ('?' ternary ':' ternary)?
or         := and ('OR' and)*
and        := coalesce ('AND' coalesce)*
coalesce   := comparison ('??' coalesce)?
comparison := addSub (comp_op addSub | 'IN' rhs | 'NOT' 'IN' rhs)?
addSub     := mulDiv (('+' | '-') mulDiv)*
mulDiv     := not (('*' | '/' | '%') not)*
not        := 'NOT' not | unary
unary      := '-' unary | '+' unary | primary
primary    := literal | identifier ('(' args ')')? | '(' ternary ')' | '[' list ']'
```

## Literals

### Integers

A sequence of decimal digits. Bounded by `PHP_INT_MAX` (positive) and `PHP_INT_MIN` (negative).

```
0, 42, 1000000
-1, -42      (the '-' is a unary operator, not part of the literal)
```

**Beyond `PHP_INT_MAX`**: throws `SyntaxErrorException` at the lex/parse phase. Exception: the exact value `|PHP_INT_MIN|` (= `PHP_INT_MAX + 1`) is accepted **only** as the operand of a unary `-`, which allows writing `PHP_INT_MIN` literally.

### Floats

Decimal notation (`.` required) or scientific notation (`e` or `E`).

```
0.5, 3.14, 1.0
1e10, 2.5E-3, -1.5e6
```

**Restrictions**:
- No `.5` form (without leading integer): must be `0.5`
- No `5.` form (without trailing decimal): must be `5.0`
- No `Infinity` or `NaN` literals. If a float literal exceeds `PHP_FLOAT_MAX`, throws `SyntaxErrorException` at lex time.

### Booleans

`true` or `false`, **case-insensitive**: `TRUE`, `True`, `tRuE` are all equivalent.

### Null

`null`, case-insensitive.

### Strings

Delimited by `'` single or `"` double quotes. **Escaping**: double the delimiter.

```
'hello world'
"hello world"
'L''Oréal'             →  L'Oréal
"He said ""hi"""       →  He said "hi"
```

**No other escaping**: `'\n'` is not a newline — it is literally the two characters `\` and `n`. Multi-line strings are possible but without interpretation.

**No interpolation**: `'Hello $name'` is the literal string.

### Lists

`[...]` notation with commas. Accept primitive literals only (no variables, no computed expressions).

```
[1, 2, 3]
['a', 'b', 'c']
[1, 'mixed', true, null]
[]                  # empty list
[-1, -2.5]          # negatives OK (the - is folded in the parser)
```

**Restrictions**:
- No computed expressions: `[1+1, 2]` throws
- No variables: `[a, b]` throws (except via a custom function that builds a list)
- No trailing comma: `[1, 2,]` throws
- `[]` is forbidden as the right-hand operand of `IN` (degenerate case with no use)

## Variables (paths)

Dot-notation. Each segment is a valid PHP identifier (letter or `_` at the start, then letters/digits/`_`).

```
total
cart.total
customer.address.country
```

**Reserved keywords** (case-insensitive): `and`, `or`, `not`, `in`, `true`, `false`, `null`. A root identifier that matches one of these keywords is tokenized as a keyword and therefore inaccessible directly:

```
'In'      →  SyntaxErrorException
'user.in' →  OK (the 'in' is a sub-segment, not the root)
```

To expose data with a root key that collides, wrap it under a parent.

**No wildcards, bracket indexing, or method calls**: `cart.items[0]`, `cart.items.0`, `cart.method()` are not supported. See `context.md` for details on resolution.

## Operators

Full list, from lowest to highest precedence. Precedences **aligned with PHP**, with one explicit exception: `??` is placed *above* AND/OR (in PHP it is below `&&`/`||`). See the `??` section below.

| # | Precedence | Operators | Type | Associativity |
|---|---|---|---|---|
| 0 | ternary | `?:` | ternary | right |
| 1 | logical OR | `OR`, `\|\|` | binary | left |
| 2 | logical AND | `AND`, `&&` | binary | left |
| 3 | null-coalesce | `??` | binary | right |
| 4 | comparison / membership | `=` (`==`), `!=`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN` | binary | non-associative |
| 5 | additive | `+`, `-` | binary | left |
| 6 | multiplicative | `*`, `/`, `%` | binary | left |
| 7 | NOT | `NOT` | unary prefix | right (recursive) |
| 8 | unary | `-`, `+` | unary prefix | right (recursive) |

The keywords `AND`, `OR`, `NOT`, `IN` are **case-insensitive**: `and`/`AND`/`And`/`aNd` are all equivalent. The symbolic alternatives `&&` and `||` are accepted for AND/OR.

### Equality (`=`, `==`, `!=`)

`=` and `==` are equivalent (the parser normalizes both to `=`). **Adapted equality**:
- Numerics (int and float): value comparison, no type traps (`5 = 5.0` → `true`)
- Other types: strict (`'5' = 5` → `TypeErrorException`)
- `null = null` → `true`, `null = <anything>` → `false` — no exception, **except** if the other operand is NaN/INF (rejected before the null shortcut, per the general policy below)
- **Arrays forbidden**: `[1,2] = [1,2]` throws (use `IN` for membership)
- **NaN/INF**: rejected (per the general policy)

### Order comparison (`>`, `>=`, `<`, `<=`)

- Numerics with numerics: OK
- Strings with strings: OK (lexicographic order). Allows correct comparison of `Y-m-d` dates.
- Mixed numeric/string: `TypeErrorException`
- Booleans, null, arrays: `TypeErrorException`

### IN / NOT IN

`x IN list` tests membership.

**Right-hand side**: a literal list `[...]`, a list-type variable, or a function returning one. **Not** a scalar (`x IN 5` throws at parse time).

**Empty list**: `x IN [...]` with an empty literal list is syntactically forbidden. A variable that resolves to an empty list at evaluation time returns `false` — that is valid data, not an error. This distinction is intentional: an empty literal list in code is always an author mistake, while an empty list at runtime is legitimate data (e.g. `tags = []` when an article has no tags).

**Scalar left-hand side**: element-by-element comparison via `looseEqual` (same rules as `=`).

**Array left-hand side** (dual semantics):
1. **Pre-pass**: test if the subject **is** an element of the list (strict array comparison). `[1,2] IN [[1,2], 3]` → `true`.
2. **Fallback intersection**: test if **at least one element** of the subject is also in the list. `[1,2] IN [1,2,3]` → `true`, `[4,5] IN [1,2,3]` → `false`.

```
'php' IN tags                       # does tags contain 'php'?
cart.tags IN ['promo', 'vip']       # is cart.tags one of the two? OR does it share an element?
5 NOT IN [1, 2, 3]                  # → true
```

**Type error policy**: if **no** pair comparison was possible (all pairs throw TypeError), the error is re-thrown. If **at least one** pair could be compared without a match, returns `false`. Prevents silently turning an error into `false`.

### Arithmetic (`+`, `-`, `*`, `/`, `%`)

- Operands: strictly `int` or `float`. Any other type throws `TypeErrorException` (no coercion of numeric strings, no `null` → `0`).
- **Overflow**: if two `int` values produce a result outside `PHP_INT_MAX` (PHP would downcast to `float`), throws. To accept the precision loss, cast explicitly to `float`: `(a + 0.0) + b`.
- **NaN/INF**: rejected on any arithmetic operation.
- **Division by zero**: `1 / 0` throws `TypeErrorException` (message "Division by zero"). `1 % 0` as well.
- **Modulo**: `int % int` → `int` (standard PHP modulo). If at least one `float`, uses `fmod`.

### `??` (null-coalescing)

`a ?? b`:
- If `a` is resolved and non-null: returns `a`
- If `a` is missing (strict mode + safe mode and explain) **or** `a = null`: returns `b`

Intentionally **low precedence** (between AND and comparison). Alignment with PHP is **partial and deliberate**:

```
a ?? b == c     →  a ?? (b == c)         (== is applied first — as in PHP)
NOT a ?? b      →  (NOT a) ?? b          (NOT has very high precedence — as in PHP)
1 ?? 2 > 5      →  1 ?? (2 > 5)          →  1 ?? false  →  1
a ?? b AND c    →  (a ?? b) AND c        (⚠ DIVERGES from PHP — see below)
```

**Deliberate divergence from PHP on AND/OR**: in PHP, `??` is *lower* than `&&`/`||` (so `a ?? b && c` means `a ?? (b && c)`). Here, `??` is *above* AND/OR (so `a ?? b AND c` means `(a ?? b) AND c`). Concrete example:

```
Native PHP:  true ?? false AND false   →  true    (true ?? (false AND false))
php-ruler:   true ?? false AND false   →  false   ((true ?? false) AND false)
```

The reason: PHP actually has **two tiers** of logical operators (`&&`/`||` high, `and`/`or` very low, below assignment and ternary). This library merges the word and symbol forms into a single AND/OR tier (SQL-like), which cannot simultaneously reproduce `??`'s exact position relative to both PHP tiers. `??` is kept "tight" (above AND/OR) so that `flag ?? default` reads as a self-contained unit in a broader boolean rule.

**Frozen and deliberate decision**: for `??` vs comparison and `??` vs NOT, PHP is followed. For `??` vs AND/OR, divergence is intentional. In all cases, parenthesize explicitly for different grouping: `a ?? (b AND c)`, `(a ?? b) == c`.

### Ternary (`? :`)

Lowest precedence. **Right-associative**:

```
a ? b ? c : d : e        →  a ? (b ? c : d) : e
```

The condition must be strictly `bool`. No truthy/falsy coercion.

```
a ? 'yes' : 'no'        # OK if a is bool
5 ? 'yes' : 'no'        # TypeErrorException
```

No "Elvis" variant (`a ?: b` is not supported): write `a ?? b` (with null-coalescing semantics) or an explicit `condition ? value : default`.

### NOT

Very high precedence (just below unary `-` / `+`), **aligned with PHP** `!`. Operand must be strictly `bool`.

```
NOT a               →  negation of a
NOT a == b          →  (NOT a) == b
NOT a AND b         →  (NOT a) AND b
NOT a + b           →  (NOT a) + b   (consistent with !$a + $b in PHP)
NOT NOT a           →  NOT (NOT a)   (recursive)
```

`NOT IN` (special combination) is treated separately: it is a "comparison" level operator (4), not a `NOT` followed by an `IN`.

### Unary `-` / `+`

Highest non-function precedence. Recursive:

```
--x   →  -(-x)   (mathematically correct)
++x   →  +(+x)   (no-op)
```

`-` requires an `int|float` operand. **No coercion**: `-null`, `-true`, `-'5'` all throw.

## Function calls

```
fn()
fn(arg)
fn(arg1, arg2)
fn(arg1, fn2(arg2), expr + 1)
```

Arguments are evaluated **before** the call (eager). Order is left to right.

Arity and expected types depend on the function. See `functions.md` for the catalogue and policies.

**Wrong arity** → `TypeErrorException` (the library validates, unlike PHP which silently ignores extra arguments).

## Parentheses

`( ... )` to group a sub-expression. Always allowed, never required (precedence is defined without ambiguity).

```
(a + b) * c
NOT (a AND b)            # needed if you want "not (a and b)"
(a ?? b) == c            # needed to override the low precedence of ??
```

## Whitespace

Spaces, tabs, and newlines are **ignored** between tokens. Whitespace **inside** a string literal is preserved.

**NBSP** (U+00A0, non-breaking space) is treated as an ordinary space **outside quoted literals**. Inside literals, it is preserved. This is useful for inputs copy-pasted from rich-text sources (Word, web).

Other Unicode whitespace (em-space, thin space, etc.): **not supported** in code. This is a deliberate choice: NBSP is the only Unicode whitespace encountered in practice in copy-pasted inputs. Extending the list would add maintenance cost for near-zero gain. If encountered outside a literal, these characters will fall through as an "unknown" token and throw `SyntaxErrorException`.

## Type semantics

### No silent coercion

The language is **strict**. None of these PHP classics are accepted:

| Expression | Behavior |
|---|---|
| `"false" AND true` | `TypeErrorException` (string is not bool) |
| `5 AND 10` | `TypeErrorException` (int is not bool) |
| `null + 1` | `TypeErrorException` (null is not a number) |
| `'5' + 1` | `TypeErrorException` (string is not a number) |
| `true = 1` | `TypeErrorException` (bool and int are not comparable) |

**Exception**: "loose equality" on numerics tolerates `int` vs `float` (`5 = 5.0` → `true`). This is useful in practice: an `int` calculation and a `float` calculation often produce semantically equal values.

### NaN and INF forbidden in the pipeline

No operation may produce or silently propagate NaN or INF. Any **participation in an operator** (arithmetic, comparison, equality, unary `-`) throws `TypeErrorException`. However, a NaN/INF value coming from the context or from a custom function **can transit** until `is_finite()` as long as it does not enter an operator — that is precisely what makes `is_finite()` usable. It is the only way to **inspect** them.

### Missing variables

In strict mode (`evaluate`), a missing variable throws `UnknownVariableException`. In safe mode and explain mode, the absence is collected. See the respective docs.

## Expression examples

### Cart rules

```
cart.total > 100
cart.total > 100 AND customer.vip = true
cart.total > 100 OR (customer.loyalty.years > 2 AND cart.items > 0)
cart.country IN ['FR', 'BE', 'CH']
```

### Calculations

```
cart.subtotal * 1.2
round(cart.total / cart.items, 2)
clamp(discount, 0, 100)
pow(base, 2) + 1
```

### With dates

```
year(today()) > 2025
dateDiff(today(), cart.created) <= 30
```

### With ternary and coalesce

```
customer.vip ? 'gold' : 'silver'
(discount ?? 0) > 0
customer.score ?? 50
```

### With NOT IN

```
customer.country NOT IN ['IR', 'KP']
```

### With `??` precedence (watch out)

```
# What you might read naturally: "(a ?? 0) > 100"
# But without parentheses:        "a ?? (0 > 100)"  →  "a ?? false"  →  a (or false if a is null)

(a ?? 0) > 100      # correct form
```

## Design decisions

### Deliberately limited surface

No assignment, no functions defined in the expression, no syntactic side effects, no control structures. The language is **intentionally narrow**: not a PHP subset, but a dedicated expression language.

### PHP alignment for precedence and semantics

When a decision had multiple defensible options (e.g. `??` above or below comparisons), PHP was the tiebreaker. Rationale: the target user is a PHP developer. Minimal surprise takes precedence over hypothetical "natural reading".

**One deliberate exception**: the position of `??` relative to AND/OR diverges from PHP (see the `??` section). This follows from the choice to merge PHP's two logical tiers (`&&`/`||` and `and`/`or`) into a single one, which is incompatible with exactly reproducing `??`'s position.

### String delimiters: `'` AND `"`

Both are accepted and **equivalent**. Rare in expression languages but useful for quoting a string containing one of the two: `"L'Oréal"` rather than `'L''Oréal'`.

### Case-insensitive keywords

`AND`, `and`, `And` are equivalent. A stylistic choice for an SQL-like look that is familiar to business users.

Consequence: the identifiers `and`, `or`, `not`, `in` are inaccessible at the root level (see Limitations).

### `?:` not supported (Elvis)

No Elvis operator (`a ?: b` which takes `a` if truthy, otherwise `b`). Rationale:
- Relies on truthy/falsy coercion, which is rejected everywhere else
- Risk of confusion with `??` (two similar but subtly different operators)
- The legitimate use case is covered by `??` (null-coalescing) or an explicit ternary

### No short-circuit on types for `??`, AND, OR

Although `??` short-circuits when the left is non-null, the left **must still be a valid type**. Same for AND/OR: `5 AND b` throws even if the right side might have short-circuited semantically.

## Known limitations

Summary of the syntactic limitations mentioned above:

- Reserved keywords inaccessible at the root level
- No wildcards, bracket indexing, or method access
- Lists: only primitive literals, no expressions
- No escaping in strings other than doubled quote
- No interpolation
- No date literals (use a string `'2026-01-15'`)
- No regex
- No Elvis operator
- No `**` (exponentiation), use `pow()`
- No bitwise operators (`&`, `|`, `^`, `<<`, `>>`)

## ⚠️ Common pitfalls

### `??` precedence — lower than comparisons

`??` has **lower precedence** than comparisons (aligned with PHP). Consequence:

```
a ?? 0 > 100       →  a ?? (0 > 100)    →  a ?? false     ← NOT (a ?? 0) > 100
(a ?? 0) > 100     →  correct form
```

Practical rule: whenever you combine `??` with a comparison operator, wrap the `??` in parentheses.

### `NOT a ?? b` — NOT has very high precedence

```
NOT a ?? b         →  (NOT a) ?? b      ← NOT NOT (a ?? b)
NOT (a ?? b)       →  correct form if that is the intent
```

This is often surprising because `NOT a ?? b` reads as "NOT (a if non-null, otherwise b)". That is not what the parser does. Parenthesize if the intent is `NOT (a ?? b)`.
