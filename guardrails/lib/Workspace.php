<?php

/**
 * Temporary copy of just the files the gates inspect, so a proof can mutate
 * freely without ever touching the real working tree (no restore step to forget).
 */
final class Workspace {
    const COPIED = array('guardrails', 'services', 'chatbot', 'system-prompt.txt');

    public static function create(string $root): string {
        $dir = sys_get_temp_dir() . '/guardrail-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        foreach (self::COPIED as $entry) {
            self::copy($root . '/' . $entry, $dir . '/' . $entry);
        }
        return $dir;
    }

    public static function destroy(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private static function copy(string $from, string $to): void {
        if (is_file($from)) {
            copy($from, $to);
            return;
        }
        if (!is_dir($from)) {
            return; // absent source: the gate's own Check decides if that matters
        }
        mkdir($to, 0700, true);
        foreach (new DirectoryIterator($from) as $item) {
            if (!$item->isDot()) {
                self::copy($item->getPathname(), $to . '/' . $item->getFilename());
            }
        }
    }
}
