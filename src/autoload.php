<?php

/**
 * src/autoload.php — minimal, dependency-free autoloader.
 *
 * For use without Composer:
 *
 *     require '/path/to/php-ruler/src/autoload.php';
 *
 * php-ruler uses sub-namespaces (Ols\PhpRuler\Parser\…, \Exception\…,
 * \Explainer\…, …), so namespace separators must be turned into directory
 * separators — a plain concatenation would leave a backslash in the path and
 * fail on case-sensitive filesystems.
 *
 * If you installed via Composer, you do not need this file: Composer's own
 * autoloader already maps the Ols\PhpRuler\ namespace to this directory.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ols\\PhpRuler\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
