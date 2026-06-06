# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First public release. When tagging, rename this section to `## [0.1.0] - YYYY-MM-DD`.

### Added

- Strict expression evaluation: `evaluate()`, `evaluateBoolean()`, `evaluateNumeric()` — no type
  coercion, with NaN/INF rejected at every operator.
- Safe mode: `evaluateSafe()` returning a `SafeResult` that collects every missing variable instead
  of throwing on the first one.
- Explain mode: `ExpressionExplainer` returning an `ExplainResult` tree — per-node status
  (evaluated / short-circuited / missing / error), resolved values, and a detail string for missing
  or errored nodes.
- Variable aliasing: `AliasResolver` with two-way translation (`humanToExpression()` /
  `expressionToHuman()`).
- AST management: LRU caching of parsed expressions, plus JSON export/import (`exportAst()` /
  `importAst()`).
- Static analysis: `extractVariables()` and `extractFunctions()`.
- Built-in functions (math, string, date, casts) and custom function registration via
  `registerFunction()`.
- Typed exception model with structured details (`SyntaxErrorException::$position`,
  `UnknownVariableException::$variablePath`).
- Language: dot-notation variables, lists, `IN` / `NOT IN`, ternary, `??`, and `AND` / `OR` / `NOT`,
  with strict comparison (`=` is equality).
- Zero runtime dependencies; PHP 8.1+; no extensions required.
- A local, Composer-free demo playground under `demo/`.

[Unreleased]: https://github.com/olivier-ls/php-ruler
