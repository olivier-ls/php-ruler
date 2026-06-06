<?php declare(strict_types=1);
namespace Ols\PhpRuler\Explainer;

/**
 * State of a node in an ExplainResult tree.
 *
 *   EVALUATED       — node was visited and produced a value
 *   SHORT_CIRCUITED — node was not visited (sibling branch resolved the parent)
 *   MISSING         — node requires a variable absent from the context
 *   ERROR           — node raised an unrecoverable exception during evaluation
 *                     (type error, division by zero, NaN/INF, unknown function…)
 *
 * Only EVALUATED nodes carry a meaningful `passed` value; for the others
 * `passed` is null.
 */
enum ExplainStatus
{
    case EVALUATED;
    case SHORT_CIRCUITED;
    case MISSING;
    case ERROR;
}
