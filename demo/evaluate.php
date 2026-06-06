<?php

/**
 * php-ruler — demo endpoint
 *
 * POST JSON:
 *   { "expression": "...", "context": {...}, "mode": "evaluate"|"validate" }
 *
 * Response (evaluate):
 *   {
 *     "result":  mixed,        // typed value when the root was fully evaluated, else null
 *     "type":    "bool"|"int"|"float"|"string"|"list"|"missing"|"error"|...,
 *     "passed":  true|false|null,   // null when the root could not be fully evaluated
 *     "status":  "evaluated"|"short_circuited"|"missing"|"error",
 *     "explain": { ...node tree... },
 *     "error":   null
 *   }
 *
 * Response (validate):
 *   { "ok": true } | { "error": "..." }
 *
 * Display is driven by explain(), which never throws on missing variables or
 * runtime type errors — it records them as node status instead. evaluate() is
 * only called to recover the concrete typed value when the root was fully
 * evaluated. This is what lets the demo show the tree (with the missing-variable
 * path or the error message) instead of a bare error string.
 */

declare(strict_types=1);

// Load the library. Prefer Composer's autoloader when the project was installed
// with `composer install`; otherwise fall back to the dependency-free demo
// autoloader so the demo runs straight from a clone (no Composer required).
$autoload = is_file(__DIR__ . '/../vendor/autoload.php')
    ? __DIR__ . '/../vendor/autoload.php'
    : __DIR__ . '/autoload.php';

if (!is_file($autoload)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'error' => 'Autoloader not found. Run the demo from the repository root: '
                 . 'php -S localhost:8000 -t demo',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $autoload;

use Ols\PhpRuler\ExpressionEvaluator;
use Ols\PhpRuler\Explainer\ExpressionExplainer;
use Ols\PhpRuler\Explainer\ExplainNode;
use Ols\PhpRuler\Explainer\ExplainStatus;
use Ols\PhpRuler\Exception\SyntaxErrorException;
use Ols\PhpRuler\Exception\TypeErrorException;
use Ols\PhpRuler\Exception\UnknownVariableException;
use Ols\PhpRuler\Exception\EvaluatorException;

header('Content-Type: application/json; charset=utf-8');

// This is a local demo: keep PHP errors out of the JSON stream and rely on the
// structured error envelope below.
ini_set('display_errors', '0');
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed — use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input) || !isset($input['expression'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing "expression" parameter.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expression = trim((string) $input['expression']);
$context    = is_array($input['context'] ?? null) ? $input['context'] : [];
$mode       = in_array($input['mode'] ?? '', ['validate', 'evaluate'], true) ? $input['mode'] : 'evaluate';

if ($expression === '') {
    echo json_encode(['error' => 'Empty expression.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $eval = new ExpressionEvaluator();

    // ── validate ─────────────────────────────────────────────────────────────
    if ($mode === 'validate') {
        $eval->validate($expression);            // throws SyntaxErrorException on invalid syntax
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── evaluate ─────────────────────────────────────────────────────────────
    // explain() never throws on missing/type errors — only on syntax errors
    // (parsing happens up front). It captures runtime issues as node status.
    $result = (new ExpressionExplainer($eval))->explain($expression, $context);

    $rootEvaluated = $result->root->status === ExplainStatus::EVALUATED;

    $value = null;
    $type  = strtolower($result->root->status->name);   // missing | error | short_circuited

    if ($rootEvaluated) {
        // Safe to fetch the concrete typed value (could be string/number for a
        // ternary, not just bool). Guarded just in case.
        try {
            $value = $eval->evaluate($expression, $context);
            $type  = inferType($value);
        } catch (Throwable) {
            $value = $result->passed;
            $type  = inferType($value);
        }
    }

    echo json_encode([
        'result'  => $value,
        'type'    => $type,
        'passed'  => $result->passed,                       // true | false | null
        'status'  => strtolower($result->root->status->name),
        'explain' => nodeToArray($result->root),
        'error'   => null,
    ], JSON_UNESCAPED_UNICODE);

} catch (SyntaxErrorException $e) {
    echo json_encode(['error' => 'Syntax error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (TypeErrorException $e) {
    echo json_encode(['error' => 'Type error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (UnknownVariableException $e) {
    echo json_encode(['error' => 'Unknown variable: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (EvaluatorException $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Safety net — never leak a stack trace.
    echo json_encode(['error' => 'Internal error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ─────────────────────────────────────────────────────────────────────────────
//  Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Serialises an ExplainNode tree to a JSON-friendly array, faithful to the
 * real model: status (evaluated|short_circuited|missing|error), the ?bool
 * `passed` kept as true/false/null (NOT coerced — null means "not evaluated",
 * distinct from "evaluated and false"), and `detail` (the missing-variable path
 * or the error message). Leaves carry leftValue/rightValue; compound nodes
 * carry children.
 */
function nodeToArray(ExplainNode $node): array
{
    $arr = [
        'expression' => $node->expression,
        'operator'   => $node->operator,
        'status'     => strtolower($node->status->name),  // EVALUATED -> "evaluated", SHORT_CIRCUITED -> "short_circuited"
        'passed'     => $node->passed,                     // true | false | null
        'detail'     => $node->detail,                     // missing path / error message / null
    ];

    if (empty($node->children)) {
        // Leaf: comparison, IN, value…
        $arr['leftValue']   = $node->leftValue;
        $arr['rightValue']  = $node->rightValue;
        $arr['leftMissing'] = $node->leftMissing;
    } else {
        // Compound: AND, OR, NOT, ?:
        $arr['children'] = array_map('nodeToArray', $node->children);
    }

    return $arr;
}

/**
 * PHP type of a value, for the result badge on the JS side.
 */
function inferType(mixed $value): string
{
    return match (true) {
        is_bool($value)   => 'bool',
        is_int($value)    => 'int',
        is_float($value)  => 'float',
        is_string($value) => 'string',
        is_array($value)  => 'list',
        $value === null   => 'null',
        default           => 'mixed',
    };
}
