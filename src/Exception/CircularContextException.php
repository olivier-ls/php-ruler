<?php declare(strict_types=1);

namespace Ols\PhpRuler\Exception;

/**
 * Thrown when a context structure exceeds the maximum allowed nesting depth.
 *
 * In practice this almost always indicates a circular reference
 * (e.g. $ctx['self'] = &$ctx;), since legitimate business contexts
 * very rarely nest beyond a handful of levels.
 */
class CircularContextException extends EvaluatorException {}
