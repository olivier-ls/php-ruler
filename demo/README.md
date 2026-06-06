# php-ruler — demo

A small local playground for writing expressions and **seeing the evaluation
trace**: which sub-conditions passed, which failed, which were short-circuited,
and — when a variable is missing or a value has the wrong type — *why* the rule
could not be evaluated.

It is a single static page (`demo.html`) talking to one PHP endpoint
(`evaluate.php`). No database, no build step, no external service.

## Run it locally

No Composer, no build step. From the repository root:

```bash
php -S localhost:8000 -t demo
```

Then open <http://localhost:8000/demo.html>.

> The demo ships its own tiny autoloader (`demo/autoload.php`), so it runs
> straight from a clone. If the project *was* installed with `composer install`,
> the endpoint transparently uses Composer's autoloader instead — either way it
> just works.

## What it shows

- **Result** — the typed value returned by the expression (`bool`, `int`,
  `float`, `string`, …), not just true/false.
- **Evaluation trace** — the expression broken down into a tree:
  - ✓ / ✗ — evaluated leaves, with their resolved left/right values
  - ○ — short-circuited (a sibling already settled the parent `AND`/`OR`/`?:`)
  - ? — a required variable was missing (the path is shown)
  - ! — a runtime error (type error, division by zero, NaN/INF, …)

The quick-example buttons and the default JSON context are wired together, so
clicking an example evaluates it immediately against the sample data.

This is a demo, not part of the library surface — it is here to illustrate the
`explain` mode, nothing more.
