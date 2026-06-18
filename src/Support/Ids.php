<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Support;

/**
 * Trace / span id generation and W3C traceparent handling.
 *
 * OTLP/JSON wants lowercase-hex ids: 32 chars (16 bytes) for a trace id,
 * 16 chars (8 bytes) for a span id. The ingestion contract additionally
 * rejects all-zero ids, so we make sure the random bytes are never empty.
 */
final class Ids
{
    /** 32 lowercase hex chars (16 random bytes), never all-zero. */
    public static function traceId(): string
    {
        return self::randomHex(16);
    }

    /** 16 lowercase hex chars (8 random bytes), never all-zero. */
    public static function spanId(): string
    {
        return self::randomHex(8);
    }

    private static function randomHex(int $bytes): string
    {
        // random_bytes is cryptographically secure and always available on 8.2+.
        // The all-zero probability is negligible, but the contract forbids it, so guard.
        do {
            $hex = bin2hex(random_bytes($bytes));
        } while (preg_match('/^0+$/', $hex) === 1);

        return $hex;
    }

    /**
     * Parse a W3C `traceparent` header into [traceId, parentSpanId, sampledFlag].
     *
     * Format: `version-traceid-spanid-flags`, e.g.
     *   00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
     *
     * Returns null when absent or malformed so the caller falls back to a fresh trace.
     * Continuing an incoming traceparent lets a single distributed trace stitch
     * together across services (e.g. an upstream gateway → this Laravel app).
     *
     * @return array{traceId: string, parentSpanId: string, sampled: bool}|null
     */
    public static function parseTraceparent(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }

        // 00-<32hex>-<16hex>-<2hex>
        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', strtolower(trim($header)), $m) !== 1) {
            return null;
        }

        // Reject the invalid all-zero trace/parent ids per the W3C spec.
        if (preg_match('/^0+$/', $m[2]) === 1 || preg_match('/^0+$/', $m[3]) === 1) {
            return null;
        }

        return [
            'traceId' => $m[2],
            'parentSpanId' => $m[3],
            // low bit of the flags byte is the "sampled" flag
            'sampled' => (hexdec($m[4]) & 0x01) === 0x01,
        ];
    }

    /**
     * Build a W3C `traceparent` value for outbound injection (optional).
     */
    public static function traceparent(string $traceId, string $spanId, bool $sampled): string
    {
        return sprintf('00-%s-%s-%02x', $traceId, $spanId, $sampled ? 1 : 0);
    }
}
