<?php
declare(strict_types=1);
namespace Vaultcast;

/** Consistent separators for library containment checks on Windows and Linux. */
final class Paths {
    public static function real(string $path) {
        $resolved = realpath($path);
        return $resolved === false ? false : str_replace('\\', '/', $resolved);
    }
}
