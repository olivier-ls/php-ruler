# Alias Resolution

## Overview

The alias resolver is a **standalone** component that translates between a "human-readable" and a "technical" representation of expressions.

Typical use case: a back-office where a business user edits rules in natural language (`"customer group = 'vip' AND cart amount > 100"`) while the context data is technically structured (`customer.group`, `cart.total`). The `AliasResolver` bridges the gap in both directions:

- **User input** → `humanToExpression()` → technical expression → evaluation
- **Stored technical expression** → `expressionToHuman()` → displayed to user

The `AliasResolver` is **self-contained**: it only performs targeted text substitution. It does not evaluate, parse, or validate semantics. It does not require an `ExpressionEvaluator` instance.

## API

```php
namespace Ols\PhpRuler;

final class AliasResolver
{
    public function add(string $path, string $alias): self;
    public function remove(string $path): self;
    public function clear(): self;
    public function all(): array;

    public function humanToExpression(string $human): string;
    public function expressionToHuman(string $expression): string;
}
```

## Behaviors

### `add(string $path, string $alias): self`

Registers a bidirectional mapping between a technical path and a human-readable alias.

```php
$resolver = new AliasResolver();
$resolver
    ->add('customer.group', 'customer group')
    ->add('cart.total',     'cart amount')
    ->add('order.shipping', 'shipping cost');
```

**Alias validation rules**:

| Rule | If violated |
|---|---|
| No quotes (`'` or `"`) | `InvalidArgumentException` |
| Not empty or whitespace-only | `InvalidArgumentException` |
| No leading or trailing spaces | `InvalidArgumentException` (suggests trim) |
| Allowed characters: ASCII letters, digits, underscores, internal whitespace, Unicode (`\x{0080}-\x{FFFF}`) | `InvalidArgumentException` |
| Must not match (case-insensitive) a keyword: `and`, `or`, `not`, `in`, `true`, `false`, `null` | `InvalidArgumentException` |
| Must be unique: the same alias cannot point to two different paths | `InvalidArgumentException` |

**Explicitly forbidden characters** (beyond the whitelist):
- `.` (would collide with dot-notation paths)
- `-` (ambiguous with the subtraction operator)
- Any punctuation, operator, or regex metacharacter (would produce a malformed expression after substitution)

```php
$resolver->add('cart.total', 'cart-total');    // InvalidArgumentException — hyphen not allowed
$resolver->add('cart.total', 'and');           // InvalidArgumentException — keyword
$resolver->add('cart.total', "cart's total");  // InvalidArgumentException — apostrophe
$resolver->add('cart.total', 'AND');           // InvalidArgumentException — case-insensitive keyword
```

### Asymmetry on re-registration

**An alias** can only point to **one** path. Attempting to reuse an alias for a different path throws.

**A path** can be re-registered with a **new alias**. In this case, the previous alias is **silently removed** (last-write-wins):

```php
$resolver->add('cart.total', 'cart amount');
$resolver->add('cart.total', 'cart total');     // OK, 'cart amount' is removed
// 'cart.total' ↔ 'cart total' from now on

$resolver->add('order.total', 'cart total');    // InvalidArgumentException
// The alias 'cart total' is already used by 'cart.total'
```

Rationale: paths are the canonical identifier. The library is designed to be configured once at bootstrap, so re-registering a path is an explicit developer decision — no additional runtime protection needed.

### `remove(string $path): self`

Removes the alias associated with the path. Silent if the path has no registered alias.

### `clear(): self`

Clears all mappings.

### `all(): array`

Returns the internal `path => alias` array.

```php
$resolver->all();
// ['customer.group' => 'customer group', 'cart.total' => 'cart amount']
```

### `humanToExpression(string $human): string`

Translates a "human-readable" expression to a "technical" one by replacing each found alias with its associated path.

```php
$resolver->humanToExpression("customer group = 'vip' AND cart amount > 100");
// → "customer.group = 'vip' AND cart.total > 100"
```

### `expressionToHuman(string $expression): string`

Reverse translation — each known path is replaced by its alias.

```php
$resolver->expressionToHuman("customer.group = 'vip' AND cart.total > 100");
// → "customer group = 'vip' AND cart amount > 100"
```

### Substitution guarantees

Both methods operate via **text substitution**, but include several guards to avoid common pitfalls:

#### 1. No replacement inside quoted literals

The expression is split into alternating segments: "outside quotes" / "inside quotes". Only outside-quote segments are processed.

```php
$resolver->add('cart.total', 'cart amount');
$resolver->humanToExpression("customer.group = 'cart amount'");
// → "customer.group = 'cart amount'"  ← literal preserved, NOT replaced
```

#### 2. Strict word boundaries

Substitutions only match occurrences delimited by something other than a letter, digit, underscore, dot, or Unicode character `\x{0080}-\x{FFFF}`:

```php
$resolver->add('cart.total', 'total');
$resolver->expressionToHuman('cart.total = subtotal');
// → 'total = subtotal'  ← "total" replaced, "subtotal" preserved

// And on the other side:
$resolver->add('sum', 'total');
$resolver->humanToExpression('total(x)');
// → 'total(x)'  ← NOT replaced: an alias cannot become a function name
```

**The `(` on the right is explicitly excluded**: an alias represents a variable, not a function. An alias followed by a parenthesis is not substituted.

#### 3. UTF-8 awareness

Regexes use `/u` mode and include the Unicode range in the word-boundary definition. This prevents corruption such as:

```php
$resolver->add('menu', 'menu');  // (hypothetical)
// Without /u and Unicode range: 'menü' would be corrupted
// With it:                       'menü' is preserved intact
```

#### 4. Longest match first

If multiple aliases overlap, the longest is tried first:

```php
$resolver
    ->add('a.b.c', 'customer group name')
    ->add('a.b',   'customer group');

$resolver->humanToExpression('customer group name = "x" AND customer group = "y"');
// → 'a.b.c = "x" AND a.b = "y"'  ← 'customer group name' matched before 'customer group'
```

#### 5. Exact case

Substitutions are **case-sensitive**. An alias registered as `'Cart Total'` does not match `'cart total'` or `'CART TOTAL'`.

Rationale: aliases are managed by developers, not end users. Approximate matching would hide typos. On the resulting expression, the Lexer will also reject invalid casing cleanly.

#### 6. Invalid UTF-8 handling

If the expression contains invalid UTF-8 sequences, the translation methods throw `InvalidArgumentException`. No silent repair attempt.

## Design decisions

### Full decoupling from the evaluator

`AliasResolver` is **independent** of `ExpressionEvaluator`. No dependency, no shared instance. You can use one without the other:

```php
// Evaluation without aliases
$eval->evaluate('cart.total > 100', $ctx);

// Aliasing without evaluation (e.g. to store a "humanized" rule in a database)
$resolver->expressionToHuman($ruleTechnical);
```

This is deliberate: these are two separate concerns.

### Text substitution, not parsing

The resolver does not parse. It performs regex replacement with guards. Benefits:
- Very fast
- Does not require the expression to be syntactically valid to alias/de-alias (useful during user input in an editor)
- Symmetric: `humanToExpression(expressionToHuman($x)) === $x` if all aliases are known

Limitations:
- No validation that paths/aliases exist in the context
- No guarantee that the result is a valid expression (but the Lexer/Parser will detect that)

### Consistent splitting strategy across components

The detection of quoted literals (to preserve their content) uses the same regex as:
- `Lexer::normalizeNbspOutsideStrings()`
- `ExpressionEvaluator::canonicalizeForCache()`

All three components are synchronized. If the literal grammar evolves (e.g. adding backticks), all three sites must be updated.

### Deliberately restricted allowed characters

The whitelist does not allow operators or punctuation. As a result, an alias cannot accidentally contain a character that would produce an invalid expression after substitution.

This is what makes the resolver reliable despite its simplicity: the set of accepted aliases is narrow enough that no ambiguity can arise in practice.

### Unicode tolerance in aliases

Unicode characters `\x{0080}-\x{FFFF}` are accepted in aliases. This covers the majority of European languages (accented characters, ñ, ü...) and basic Asian scripts (CJK).

⚠️ This range **does not include**:
- Characters beyond the BMP (Basic Multilingual Plane) — emojis, some rare Asian characters...
- Unicode normalization (NFC vs NFD) — `'café'` in NFC and `'café'` in NFD will not match, even if they display identically.

For the targeted French/English back-office use case, this is more than sufficient. Extend if needed for other alphabets.

## Known limitations

### No semantic matching

The resolver ignores syntactic context. As a result, if an alias and a legitimate variable name collide, the resolver substitutes. Pathological example:

```php
$resolver->add('a.b', 'foo');
$resolver->humanToExpression('foo > 5');  // → 'a.b > 5'

// But if the user typed 'foo' meaning a different variable:
// there is no way to distinguish
```

In practice, this risk is mitigated by:
- Strict alias validation (no operators, no ambiguous characters)
- The convention that "aliases are descriptive words or phrases", very different from technical paths

### No partial hierarchical aliases

If you alias `cart.total` as `'cart total'`, the resolver knows nothing about an alias for `cart` itself (unless you register it separately). No automatic propagation.

### Back-office-only aliasing

The resolver is designed for the presentation layer. The evaluator knows nothing about aliases. If you want `evaluate()` to accept aliases directly, translate first:

```php
$human = $userInput;
$technical = $resolver->humanToExpression($human);
$result = $eval->evaluate($technical, $context);
```

### No translation cache

Each call to `humanToExpression()` / `expressionToHuman()` rebuilds the regex pattern. For repeated bulk translations on the same aliases, an external cache may be appropriate — but in practice, the typical usage (one translation per request) is not a concern.
