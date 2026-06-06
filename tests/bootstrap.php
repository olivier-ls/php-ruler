<?php declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads the project autoloader. Adjust the path below if your composer
 * vendor/ directory is located elsewhere relative to this file.
 */

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',     // tests/ at project root, vendor/ alongside
    __DIR__ . '/../../vendor/autoload.php',  // tests/ nested one level deeper
    __DIR__ . '/vendor/autoload.php',        // edge case
];

foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        return;
    }
}

throw new RuntimeException(
    "Could not locate composer autoloader. Tried:\n  " .
    implode("\n  ", $autoloadCandidates) .
    "\nRun `composer install` or adjust the paths in tests/bootstrap.php."
);
