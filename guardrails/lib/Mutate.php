<?php

/**
 * Mutations applied to a throw-away copy of the project. Each one throws when
 * its target text is gone, so a refactor that moves the guarded code surfaces
 * as "mutation could not apply" instead of a proof that quietly proves nothing.
 */
final class Mutate {

    /** Replace exactly one occurrence of $search in $file (path relative to root). */
    public static function replaceText(string $file, string $search, string $replace): callable {
        return function (string $root) use ($file, $search, $replace): void {
            $path = $root . '/' . $file;
            $source = is_file($path) ? file_get_contents($path) : false;
            if ($source === false) {
                throw new RuntimeException("mutation target file missing: $file");
            }
            $count = substr_count($source, $search);
            if ($count !== 1) {
                throw new RuntimeException("mutation target found $count times (expected 1) in $file: $search");
            }
            file_put_contents($path, str_replace($search, $replace, $source));
        };
    }

    /** Disable one detector rule by rewriting the value on its one-line definition. */
    public static function disableRule(string $file, string $ruleId, string $neverMatches): callable {
        return function (string $root) use ($file, $ruleId, $neverMatches): void {
            $path = $root . '/' . $file;
            $source = is_file($path) ? file_get_contents($path) : false;
            if ($source === false) {
                throw new RuntimeException("mutation target file missing: $file");
            }
            $pattern = '/^(\s*\'' . preg_quote($ruleId, '/') . '\'\s*=>\s*).*,$/mu';
            $updated = preg_replace($pattern, '$1' . addcslashes($neverMatches, '\\$') . ',', $source, -1, $count);
            if ($count !== 1) {
                throw new RuntimeException("rule line '$ruleId' found $count times (expected 1) in $file");
            }
            file_put_contents($path, $updated);
        };
    }
}
