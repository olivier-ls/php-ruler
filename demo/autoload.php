<?php

/**
 * demo/autoload.php — minimal, dependency-free autoloader.
 *
 * Lets the demo run straight from a clone, without `composer install`:
 *
 *     php -S localhost:8000 -t demo
 *
 * Unlike a flat library, php-ruler uses sub-namespaces
 * (Ols\PhpRuler\Parser\…, \Exception\…, \Explainer\…, …), so the namespace
 * separators must be turned into directory separators — a plain string
 * concatenation would produce a backslash in the path and fail on Linux.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ols\\PhpRuler\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));               // e.g. "Parser\Parser"
    $path     = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
