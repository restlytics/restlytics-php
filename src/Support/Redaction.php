<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Support;

/** Privacy boundary shared by every Laravel instrumentation path. */
final class Redaction
{
    private const REDACTED = '[REDACTED]';

    /** @var array<string, true> */
    private const SENSITIVE_SEGMENTS = [
        'authorization' => true,
        'auth' => true,
        'cookie' => true,
        'cookies' => true,
        'setcookie' => true,
        'password' => true,
        'passwd' => true,
        'secret' => true,
        'token' => true,
        'accesstoken' => true,
        'refreshtoken' => true,
        'apikey' => true,
        'credential' => true,
        'credentials' => true,
        'body' => true,
        'payload' => true,
        'form' => true,
        'stack' => true,
        'stacktrace' => true,
        'log' => true,
        'err' => true,
        'error' => true,
        'exception' => true,
        'binding' => true,
        'bindings' => true,
    ];

    public static function isSensitiveAttributeKey(string $key): bool
    {
        $normalized = str_replace(['-', '_'], '.', strtolower(trim($key)));
        if (\in_array($normalized, [
            'http.request.method',
            'http.response.status.code',
            'restlytics.bindings.count',
        ], true)) {
            return false;
        }

        foreach (explode('.', $normalized) as $segment) {
            if (isset(self::SENSITIVE_SEGMENTS[$segment])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove credentials/fragments and redact every query value. The key list is
     * retained for config compatibility; safety does not depend on guessed names.
     *
     * @param  list<string>  $queryKeys
     */
    public static function url(string $url, array $queryKeys = []): string
    {
        unset($queryKeys);
        $parts = parse_url($url);
        if ($parts === false) {
            $clean = explode('#', $url, 2)[0];
            $clean = explode('?', $clean, 2)[0];

            return preg_replace('#^(https?://)[^/@]+@#i', '$1', $clean) ?? '';
        }

        $params = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach (array_keys($params) as $key) {
                $params[$key] = 'REDACTED';
            }
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = $params !== [] ? '?'.http_build_query($params) : '';

        return $scheme.$host.$port.$path.$query;
    }

    /** Exception text is intentionally omitted; Restlytics is not a crash tracker. */
    public static function exceptionMessage(?string $message): ?string
    {
        unset($message);

        return null;
    }

    /**
     * Scrub common credential and personal-data forms from an application log
     * message before it enters the SDK buffer. This is deliberately applied at
     * capture time so neither preview nor a failing transport can observe the
     * original message.
     */
    public static function logText(string $message, int $maxLength = 8192): string
    {
        $text = mb_substr($message, 0, max(0, $maxLength));
        $patterns = [
            '/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----[\s\S]*?-----END (?:[A-Z0-9]+ )*PRIVATE KEY-----/iu' => self::REDACTED,
            // URL userinfo and query/fragment values.
            '#(https?://)[^\s/@]+@#iu' => '$1'.self::REDACTED.'@',
            '/([?&][^=&#\s]+)=([^&#\s]*)/u' => '$1='.self::REDACTED,
            // Authorization schemes, JWTs, and common key=value credentials.
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]+/iu' => '$1 '.self::REDACTED,
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/u' => self::REDACTED,
            '/\b(password|passwd|secret|token|api[_-]?key|access[_-]?token|authorization|bindings?|request[_ .-]?body|response[_ .-]?body|payload|exception|stack)\s*[:=]\s*([^\s,;&]+)/iu' => '$1='.self::REDACTED,
            // Email addresses are personal data; preserve no local/domain fragments.
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu' => self::REDACTED,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? self::REDACTED;
        }

        return $text;
    }
}
