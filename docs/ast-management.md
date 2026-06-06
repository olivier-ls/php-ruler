# AST Compilation and Serialization

## Overview

This section covers the lifecycle management of ASTs (Abstract Syntax Trees): compilation from a string, internal cache, export for external storage, and secure import. These are the mechanisms that enable optimization of repeated evaluations and persistence of compiled expressions.

Three aspects:
1. **On-demand compilation** — `getAst()` parses an expression and caches the result for reuse
2. **Internal cache** — automatic LRU, transparent, capped at 500 entries by default
3. **Export / import** — `exportAst()` and `importAst()` allow storing a compiled AST in a database or file and reloading it without going through the Lexer/Parser again

## API

```php
public function getAst(string $expression): Node
public function exportAst(string $expression): string
public function importAst(string $serialized): Node

public function clearCache(): self
public function cacheSize(): int
```

The `Node` object returned is an interface implemented by the following classes:
`LiteralNode`, `VariableNode`, `UnaryNode`, `BinaryNode`, `InNode`, `FunctionNode`, `TernaryNode`.

## Behaviors

### `getAst(string $expression): Node`

Compiles the expression and returns the AST. Caches the result for reuse.

**Internal pipeline**:
1. Canonicalize the expression for the cache key (collapse whitespace outside literals)
2. If the key exists in the cache, return directly (and move LRU position)
3. Otherwise: `Lexer::tokenize()` then `Parser::parse()`; cache the result

**Examples**:
```php
$ast1 = $eval->getAst('a > 0');
$ast2 = $eval->getAst('a > 0');
// $ast1 === $ast2 (same instance, served from cache)

$ast3 = $eval->getAst('a>0');   // no spaces
// $ast3 !== $ast1 — distinct cache keys (see canonicalization)
```

**Exceptions thrown**:
- `SyntaxErrorException` — lex or parse error. The cache is **not modified** in this case (no preemptive eviction).

### LRU Cache

Characteristics:
- Maximum size: configurable via the constructor (default: **500 entries**, see below)
- Eviction policy: simple LRU (least recently used entry is removed when the cache is full)
- Implementation: PHP array with repositioning on each hit (unset + reassignment)
- Atomicity: if parsing fails, no eviction occurs (the cache remains consistent)
- **`0` disables the cache**: expressions are re-parsed on every call (useful for tests or memory-constrained environments)

### Configurable cache via the constructor

The cache size is set at construction time:

```php
// Default: 500 entries
$eval = new ExpressionEvaluator();

// Larger cache for a batch with many distinct expressions
$eval = new ExpressionEvaluator(cacheMaxSize: 2000);

// Cache disabled
$eval = new ExpressionEvaluator(cacheMaxSize: 0);
```

`$cacheMaxSize` must be `>= 0`; a negative value throws `\InvalidArgumentException`.

**Rationale for the default of 500**: a low threshold like 50 would cause frequent evictions in back-office usage; a very high threshold (10,000+) consumes memory without real gain. 500 comfortably covers observed use cases.

### Cache key canonicalization

The cache key is **not** the raw expression — it is a normalized version:
- Runs of whitespace (spaces, tabs, NBSP) are collapsed to a single space
- Leading and trailing whitespace is removed
- Whitespace **inside quoted literals** is preserved

```php
// All of these share the SAME cache entry:
"a > 1"
"  a > 1  "
"a  >  1"
"a\t>\t1"
"a\xC2\xA0>\xC2\xA01"  // NBSP

// But these two are DIFFERENT:
"a = 'x  y'"
"a = 'x y'"
// → distinct literals (two spaces vs one)

// And these two as well, despite semantic equivalence:
"1+1"   → key "1+1"
"1 + 1" → key "1 + 1"
```

**Rationale for the limitation**: canonicalization does not normalize spaces around operators. Doing so would require partial tokenization at the cache level, duplicating the Lexer's grammar. The trade-off: suboptimal cache fragmentation, but simplicity and robustness.

**Recommendation**: if you generate expressions programmatically and want to maximize the hit rate, use a consistent spacing style.

### UTF-8 robustness

Canonicalization uses PCRE's `/u` mode. On invalid UTF-8:
- Falls back to the raw expression as the key (rather than risking an empty key that would collide with all other invalid entries)
- Downstream parsing will reject the invalid input with a clear error

### `exportAst(string $expression): string`

Compiles the expression and returns its serialized form for external storage. The format is a **versioned JSON envelope**:

```json
{"v": 1, "ast": "<PHP-serialized-AST>"}
```

The `v` field identifies the format version. Any incompatible change to the Node structure bumps this version, and `importAst()` will reject payloads from older versions with a clear message.

```php
$serialized = $eval->exportAst('cart.total > threshold');
// Store $serialized in a database...

$ast = $eval->importAst($serialized);
$result = $eval->evaluateAst($ast, $context);
```

**Exceptions thrown**:
- `SyntaxErrorException` — same as `getAst()`

### `importAst(string $serialized): Node`

Deserializes and **validates** an exported AST. Returns a `Node` usable with the `evaluateAst*` / `explainAst` methods.

Validation performed:
1. **JSON envelope decoding**: verifies that the payload is valid JSON with the `v` and `ast` fields.
2. **Version check**: if `v` does not match `AST_EXPORT_VERSION` (currently `1`), immediately rejected with a message asking to re-compile.
3. **Class restriction**: `unserialize()` is called with `allowed_classes` limited to the library's Node hierarchy. No external class can be instantiated.
4. **Cycle detection**: the AST is walked via `SplObjectStorage` to detect any circular reference. Cyclic ASTs are rejected.
5. **Depth limit**: 200 levels maximum (`IMPORT_AST_MAX_DEPTH`), mirroring the evaluation limit. Beyond that, rejected.

**Exceptions thrown**:
- `\InvalidArgumentException` — invalid payload (malformed JSON, missing fields, version mismatch, unauthorized class, cycle detected, depth exceeded)

```php
// Importing a payload from an older version of the library:
$ast = $eval->importAst($oldPayload);
// → InvalidArgumentException: 'importAst(): AST export version mismatch — got v0, expected v1.
//    Re-compile the expression with exportAst() to refresh the stored payload.'
```

### Versioning policy

The internal constant `AST_EXPORT_VERSION` (currently `1`) is bumped on every incompatible change to the Node structure (new properties, renamed classes, removed nodes). This forces callers to re-compile stored expressions rather than silently running a misinterpreted AST.

### Security of `importAst()`

⚠️ **Never call `importAst()` with data from an untrusted source.**

Even with `allowed_classes` restricted, `unserialize()` may have other surprises depending on the PHP version. The validation performed by the library is a **defense in depth**, not a blanket permission to deserialize arbitrary user input.

Legitimate use case: an AST exported **by your own application**, stored in **your own database or cache**, under your control. The caller assumes trust in the provenance.

### Node sharing (DAG-like)

Cycle detection uses **active path tracking** (`attach()` on descent, `detach()` on ascent). As a result, the same node referenced by two siblings (e.g. the same `LiteralNode` shared between two function arguments) is **not** considered cyclic.

```
                FunctionNode
               /            \
        LiteralNode(5)   LiteralNode(5)   ← same instance, OK
```

This tolerance is deliberate: it leaves the door open for a potential future optimization that interns identical literals.

### `clearCache(): self` and `cacheSize(): int`

- `clearCache()`: completely clears the cache. Returns `$this` for chaining.
- `cacheSize()`: number of entries currently in cache.

Typically useful for tests, or to free memory after a large batch.

```php
$eval->clearCache();
assert($eval->cacheSize() === 0);
```

## Design decisions

### Transparent and automatic cache

The cache works without any caller intervention. Evaluating the same expression 1,000 times only triggers one parse, with no configuration needed. Callers can ignore its existence entirely.

### Configurable cache

The cache size (default 500) is set via the constructor. `0` disables the cache. See the "LRU Cache" section for details.

### Per-instance independent cache

The cache is an instance property, not static. Two instances of `ExpressionEvaluator` do not share their cache. This makes sense: custom functions registered via `registerFunction()` are also per-instance, so two instances may resolve the same expression differently.

### Export: versioned JSON envelope

The export produces a JSON `{"v": 1, "ast": "<serialize()>"}`. This wrapping adds:
- **Versioning**: immediate detection of stale payloads instead of a silently incorrect `unserialize()`.
- **Minimal interoperability**: the JSON is readable by non-PHP tools to inspect the version, even if the serialized AST itself is opaque.

Drawbacks:
- Slightly larger format (JSON envelope overhead, marginal).
- Coupled to the internal Node class structure (a signature refactoring may invalidate ASTs stored in a database) — but the version field allows detecting and handling this cleanly.

### Validation separate from deserialization

Validation (cycles + depth) is **not** delegated to PHP — it is done explicitly after `unserialize()`. Reason: PHP supports circular references in serialization, so `unserialize()` silently recreates them. Without validation, a cyclic AST would be valid from PHP's perspective but would cause a stack overflow in the evaluator.

The evaluator also has its own guard (`MAX_EVAL_DEPTH`), but import-time validation provides an earlier and clearer error.

## Known limitations

### No distributed cache

The cache is local to the instance. In a multi-process / multi-server architecture, each process maintains its own cache. To share it, use `exportAst()` + an external cache (Redis...) + `importAst()`.

### Cache size configurable but per-instance only

The cache size is set via the constructor (`cacheMaxSize`, default 500, `0` to disable). See the "Configurable cache via the constructor" section. It remains **per-instance**: no global setting, and each instance has its own cache (see "Per-instance independent cache").

### No TTL

An entry stays in cache until evicted by LRU. No time-based invalidation. In practice, this is rarely relevant: a compiled AST is immutable (no external dependencies).

### Format coupling on export

The `serialize()` format is tied to the internal structure. See Design decisions.

## Limits and constants

| Constant | Value | Location | Description |
|---|---|---|---|
| `MAX_DEPTH` | 64 | `ContextResolver` | Max context depth before `CircularContextException` |
| `MAX_EVAL_DEPTH` | 200 | `Evaluator` | Max AST evaluation depth (strict and safe modes) |
| `IMPORT_AST_MAX_DEPTH` | 200 | `ExpressionEvaluator` | Max depth accepted by `importAst()` |
| `AST_EXPORT_VERSION` | 1 | `ExpressionEvaluator` | Export format version, checked on import |
| `CACHE_MAX_SIZE` (default) | 500 | `ExpressionEvaluator` | Max LRU cache size (configurable via constructor) |

These values are not configurable except for the cache size (see constructor). They were chosen to comfortably cover real-world use cases while protecting against abuse — none has caused issues in practice.
