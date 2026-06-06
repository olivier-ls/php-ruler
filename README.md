# php-ruler

[![CI](https://github.com/olivier-ls/php-ruler/actions/workflows/ci.yml/badge.svg)](https://github.com/olivier-ls/php-ruler/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/ols/php-ruler)](https://packagist.org/packages/ols/php-ruler)
[![PHP Version](https://img.shields.io/packagist/dependency-v/ols/php-ruler/php)](https://packagist.org/packages/ols/php-ruler)
[![License](https://img.shields.io/github/license/olivier-ls/php-ruler)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/ols/php-ruler)](https://packagist.org/packages/ols/php-ruler)

A transparent expression & rule evaluator in pure PHP.
Strict typing, no dependencies, and an explain mode that shows exactly why a rule passed or failed.

`Strict typing` · `Safe mode` · `Full evaluation trace` · `Variable aliasing` · `Zero dependencies` · `PHP 8.1+`

---

## Who is this for?

php-ruler exists to move business logic *out* of your code and into expressions that can be
stored, edited, and evaluated at runtime — pricing rules, eligibility checks, feature flags,
validation conditions, content filters — without redeploying. It is also a practical way to let
non-developers author rules, because every evaluation can be explained step by step.

If you need to traverse objects, call methods, or you are already invested in the Symfony stack,
Symfony's [ExpressionLanguage](https://symfony.com/doc/current/components/expression_language.html)
is the mature, battle-tested choice. php-ruler deliberately does **less**: it never touches objects,
methods, or PHP internals. That is precisely what lets it stay strict, dependency-free, and safe to
expose to semi-trusted rule authors — and what lets it tell you *why* a rule evaluated the way it did.

**It is a good fit if:**

- You want to store and evaluate rules without redeploying code
- You want strict, predictable semantics (no silent type juggling)
- You let users or non-developers write rules, and need to explain the outcome
- You want zero dependencies and nothing to install beyond the library itself

**It is not a good fit if:**

- You need to read object properties or call methods inside expressions
- You need the full operator surface of Twig/Symfony ExpressionLanguage
- You want to run arbitrary, fully untrusted input without any review (see [Security](#security))

---

## Features

- **Strict evaluation** — no type coercion. `1 = '1'` is a type error, not `true`. NaN/INF can never
  silently slip through an operator. ([`docs/evaluate.md`](docs/evaluate.md))
- **Safe mode** — evaluate against a partial context: instead of throwing on the first missing
  variable, it collects every missing path so you can report them all at once.
  ([`docs/evaluate-safe.md`](docs/evaluate-safe.md))
- **Explain mode** — turn any expression into a tree showing, leaf by leaf, what was evaluated,
  what passed or failed, what was short-circuited, and *why* something could not be evaluated
  (the missing variable, the type error). ([`docs/explainer.md`](docs/explainer.md))
- **Variable aliasing** — translate between a human-facing form (`"cart amount > 100"`) and the
  technical paths your context actually uses (`cart.total > 100`), in both directions.
  ([`docs/alias-resolver.md`](docs/alias-resolver.md))
- **AST caching & export** — parsed expressions are cached (LRU); ASTs can be exported to JSON and
  re-imported, so you can pre-compile once and store the result.
  ([`docs/ast-management.md`](docs/ast-management.md))
- **Custom functions** — register your own callables alongside the built-ins.
  ([`docs/functions.md`](docs/functions.md))
- **A clear error model** — typed exceptions with structured details (the offending variable path,
  the syntax error position). ([`docs/exceptions.md`](docs/exceptions.md))
- **No extensions required** — runs on any standard PHP 8.1+ installation.

---

## Requirements

- PHP **8.1** or higher
- No PHP extensions, no external services, no dependencies

---

## Installation

**Via Composer**

```bash
composer require ols/php-ruler
```

**Manual install** — if you are not using Composer, copy the `src/` directory into your project
and include its autoloader:

```php
require '/path/to/php-ruler/src/autoload.php';
```

---

## Quick start

```php
use Ols\PhpRuler\ExpressionEvaluator;

$eval = new ExpressionEvaluator();

$context = [
    'cart'     => ['total' => 150.00],
    'customer' => ['group' => 'vip', 'vip' => true],
    'product'  => ['price' => 49.99],
];

$eval->evaluate("cart.total > 100 AND customer.group = 'vip'", $context); // true
```

Variables are read from the context by dot-notation path. Evaluation is strict: comparing
incompatible types, or using a missing variable, raises a typed exception rather than guessing.

---

## Evaluating

The strict entry points throw on the first problem (missing variable, type error, syntax error):

```php
$eval->evaluate("round(cart.total * 0.9, 2)", $context);   // mixed  → 135.0
$eval->evaluateBoolean("cart.total >= 50", $context);      // bool   → true
$eval->evaluateNumeric("product.price * 2", $context);     // float  → 99.98
```

→ Detailed documentation: [`docs/evaluate.md`](docs/evaluate.md)

## Safe mode

When the context may be incomplete, `evaluateSafe()` does not throw on missing variables — it
reports them so you can decide what to do:

```php
$result = $eval->evaluateSafe("cart.total > customer.creditLimit", $context);

$result->success;       // false
$result->missingVars;   // ['customer.creditLimit']
$result->getValueOr(false);
```

→ Detailed documentation: [`docs/evaluate-safe.md`](docs/evaluate-safe.md)

## Explain mode

`explain()` returns the full evaluation tree — the part that makes a rule auditable instead of a
black box:

```php
use Ols\PhpRuler\Explainer\ExpressionExplainer;

$explainer = new ExpressionExplainer($eval);
$result    = $explainer->explain(
    "customer.vip = true AND cart.total >= 50 AND product.price < 10 OR customer.group = 'vip'",
    $context
);

$result->passed;        // true | false | null (null = could not be fully evaluated)
$result->failures();    // evaluated leaves that returned false
$result->missing();     // leaves that needed an absent variable
$result->root;          // the full tree (expression, status, passed, resolved values, children)
```

Each node carries its reconstructed sub-expression, its status (evaluated / short-circuited /
missing / error), the resolved left/right values, and — for missing or errored nodes — a detail
string (the variable path or the error message).

→ Detailed documentation: [`docs/explainer.md`](docs/explainer.md)

## Variable aliasing

`AliasResolver` is a standalone, two-way text translator between a human-facing form and your
technical paths. It does not parse or evaluate — it only substitutes — so it composes cleanly with
the evaluator:

```php
use Ols\PhpRuler\AliasResolver;

$resolver = (new AliasResolver())
    ->add('customer.group', 'customer group')
    ->add('cart.total',     'cart amount');

// Human input → technical expression → evaluate
$expr = $resolver->humanToExpression("customer group = 'vip' AND cart amount > 100");
// "customer.group = 'vip' AND cart.total > 100"
$eval->evaluate($expr, $context);

// Stored technical expression → human form for display
$resolver->expressionToHuman("cart.total > 100"); // "cart amount > 100"
```

→ Detailed documentation: [`docs/alias-resolver.md`](docs/alias-resolver.md)

---

## What you can write

- **Types** — integers, floats, single/double-quoted strings, `true` / `false`, `null`, and lists
  (`['a', 'b', 'c']`).
- **Operators** — arithmetic (`+ - * / %`); comparison (`=`, `!=`, `<`, `<=`, `>`, `>=` — note `=`
  is equality); logical `AND` / `OR` / `NOT`; membership `IN` / `NOT IN`; null-coalescing `??`;
  and the ternary `? :`.
- **Variables** — dot-notation paths into the context (`customer.address.city`).
- **Functions** — math (`round`, `floor`, `ceil`, `abs`, `min`, `max`, `clamp`, `pow`, `sqrt`),
  strings (`length`, `upper`, `lower`, `trim`, `contains`, `startsWith`, `endsWith`, `substr`),
  dates (`today`, `now`, `year`, `month`, `day`, `dateDiff`, `dateAdd`, …), and casts (`int`,
  `float`, `bool`, `str`) — plus any custom function you register.

A few examples:

```text
product.category IN ['clothing', 'shoes', 'boots']
customer.group NOT IN ['blocked', 'banned']
customer.vip = true ? 'premium' : 'standard'
customer.discount ?? 0
dateDiff(today(), order.date) > 0
```

The full grammar, the operator precedence table (including the deliberate placement of `??`), and
the strict-typing rules are documented in
[`docs/language-reference.md`](docs/language-reference.md) and
[`docs/functions.md`](docs/functions.md).

---

## Security

php-ruler evaluates only the context you pass in. It cannot read object properties, call methods,
reach PHP constants, or touch the filesystem — there is no escape hatch into the host. That makes it
well suited to evaluating rules authored in a back office or by semi-trusted users.

It is not, however, a hardened sandbox for arbitrary hostile input out of the box: a registered
regex-style custom function could expose ReDoS, and pathologically deep expressions are bounded by a
depth guard but still cost CPU. Review or constrain rules that come from fully untrusted sources.

---

## Documentation

| Topic | Document |
|---|---|
| Language reference (grammar, operators, precedence, typing) | [`docs/language-reference.md`](docs/language-reference.md) |
| Built-in functions | [`docs/functions.md`](docs/functions.md) |
| Evaluating (strict) | [`docs/evaluate.md`](docs/evaluate.md) |
| Safe mode | [`docs/evaluate-safe.md`](docs/evaluate-safe.md) |
| Explain mode | [`docs/explainer.md`](docs/explainer.md) |
| Variable aliasing | [`docs/alias-resolver.md`](docs/alias-resolver.md) |
| Context resolution | [`docs/context.md`](docs/context.md) |
| AST caching & export | [`docs/ast-management.md`](docs/ast-management.md) |
| Static analysis (extract variables / functions) | [`docs/static-analysis.md`](docs/static-analysis.md) |
| Exceptions & error model | [`docs/exceptions.md`](docs/exceptions.md) |

---

## Demo

A small local playground to write expressions and **see the evaluation trace** — which
sub-conditions passed, which failed, which were short-circuited, and, when a variable is missing or a
value has the wrong type, *why* the rule could not be evaluated.

No build step, no Composer required:

```bash
php -S localhost:8000 -t demo
```

Then open <http://localhost:8000/demo.html>.

![php-ruler demo — an expression evaluated, with its full evaluation trace](https://github.com/olivier-ls/php-ruler/raw/main/demo/demo.png)

See [`demo/README.md`](demo/README.md) for details.

---

## License

MIT — see [LICENSE](LICENSE).
