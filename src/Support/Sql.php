<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Support;

/**
 * SQL normalization → a literal-free template string.
 *
 * Two jobs:
 *  1. PII / redaction — strip every literal so we NEVER ship customer values
 *     (emails, tokens, ids) inside `db.query.summary`. Only the shape survives.
 *  2. N+1 grouping — collapse the query down to a stable fingerprint so that
 *     `SELECT * FROM users WHERE id = 1` and `... id = 2` map to the same key.
 *     `IN (?, ?, ?)` lists of varying length also collapse to `IN (?)` so a
 *     batched query and its single-row cousin don't fragment the grouping.
 *
 * This is deliberately a best-effort lexical normalizer, not a real SQL parser —
 * it must be fast (runs on every query) and never throw.
 */
final class Sql
{
    /**
     * Normalize a raw SQL string into a stable, literal-free template.
     */
    public static function normalize(string $sql): string
    {
        $s = $sql;

        // Drop string literals: single- and double-quoted, with escaped-quote support.
        // Replace with `?` so they read like positional bindings.
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $s) ?? $s;
        $s = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '?', $s) ?? $s;

        // Normalize existing placeholders FIRST, before numeric stripping — otherwise
        // the digit in `$1`/`?2` would be eaten by the numeric-literal pass and leave
        // a stray sigil behind.
        $s = preg_replace('/[:$]\w+/', '?', $s) ?? $s;          // :name, $1
        $s = preg_replace('/\?\d+/', '?', $s) ?? $s;            // ?1, ?2 (some drivers)

        // Drop numeric literals (ints, decimals, scientific, hex). Use word
        // boundaries so we don't mangle identifiers like `column2`.
        $s = preg_replace('/\b0x[0-9a-fA-F]+\b/', '?', $s) ?? $s;
        $s = preg_replace('/\b\d+\.\d+(?:[eE][+-]?\d+)?\b/', '?', $s) ?? $s;
        $s = preg_replace('/\b\d+\b/', '?', $s) ?? $s;

        // Collapse `IN (?, ?, ?)` → `IN (?)` so list length doesn't fragment groups.
        $s = preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $s) ?? $s;

        // Collapse multi-row VALUES tuples: (?, ?), (?, ?) → (?)
        $s = preg_replace('/\(\s*\?(?:\s*,\s*\?)*\s*\)(?:\s*,\s*\(\s*\?(?:\s*,\s*\?)*\s*\))+/', '(?)', $s) ?? $s;
        $s = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $s) ?? $s;

        // Squash all whitespace runs (incl. newlines) into single spaces, then trim.
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = trim($s);

        // Lowercase so casing differences don't fragment the grouping key.
        return strtolower($s);
    }
}
