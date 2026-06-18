<?php

declare(strict_types=1);

namespace Restlytics\Laravel;

/**
 * A single span, accumulated in-request and serialized to OTLP/JSON on flush.
 *
 * Timestamps are kept as integer nanoseconds internally and only stringified at
 * serialization time — the OTLP/JSON contract requires *UnixNano fields to be
 * decimal STRINGS (to preserve 64-bit precision through JSON).
 *
 * Attribute values are kept as raw PHP scalars in $attributes and converted to
 * the OTLP AnyValue wrapper ({"stringValue"|"intValue"|...}) at serialization.
 * The single most error-prone rule lives here: intValue MUST be a string.
 */
final class Span
{
    /** OTLP SpanKind enum values we use. */
    public const KIND_SERVER = 2;
    public const KIND_CLIENT = 3;

    /** OTLP status codes. */
    public const STATUS_UNSET = 0;
    public const STATUS_OK = 1;
    public const STATUS_ERROR = 2;

    /** @var array<string, scalar> raw attribute values (key => php scalar) */
    private array $attributes = [];

    /**
     * Distinguish ints from floats/bools for OTLP AnyValue serialization, since
     * a value like `200` could be either. Keys present here are forced to intValue.
     *
     * @var array<string, true>
     */
    private array $intKeys = [];

    private int $statusCode = self::STATUS_UNSET;
    private ?string $statusMessage = null;

    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public string $name,
        public readonly int $kind,
        public readonly int $startUnixNano,
        public int $endUnixNano,
    ) {
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setEnd(int $endUnixNano): self
    {
        $this->endUnixNano = $endUnixNano;

        return $this;
    }

    public function setString(string $key, string $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /** Record an int attribute. Serialized as intValue (a STRING) per the contract. */
    public function setInt(string $key, int $value): self
    {
        $this->attributes[$key] = $value;
        $this->intKeys[$key] = true;

        return $this;
    }

    public function setDouble(string $key, float $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function setBool(string $key, bool $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function setStatus(int $code, ?string $message = null): self
    {
        $this->statusCode = $code;
        if ($message !== null) {
            // Cap to keep payloads bounded; full stack traces don't belong on the wire.
            $this->statusMessage = mb_substr($message, 0, 1024);
        }

        return $this;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** Duration in nanoseconds (clamped non-negative against clock skew). */
    public function durationNs(): int
    {
        return max(0, $this->endUnixNano - $this->startUnixNano);
    }

    /**
     * Serialize to the OTLP/JSON Span shape the ingestion contract validates.
     *
     * @return array<string, mixed>
     */
    public function toOtlpArray(): array
    {
        $span = [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'name' => $this->name,
            'kind' => $this->kind,
            // Decimal STRINGS — int64-safe in JSON.
            'startTimeUnixNano' => (string) $this->startUnixNano,
            'endTimeUnixNano' => (string) $this->endUnixNano,
        ];

        // parentSpanId is omitted/empty for the root SERVER span.
        if ($this->parentSpanId !== null && $this->parentSpanId !== '') {
            $span['parentSpanId'] = $this->parentSpanId;
        }

        if ($this->attributes !== []) {
            $span['attributes'] = $this->serializeAttributes();
        }

        // Only attach status when it carries signal (OK/ERROR); UNSET is the default.
        if ($this->statusCode !== self::STATUS_UNSET) {
            $status = ['code' => $this->statusCode];
            if ($this->statusMessage !== null && $this->statusMessage !== '') {
                $status['message'] = $this->statusMessage;
            }
            $span['status'] = $status;
        }

        return $span;
    }

    /**
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function serializeAttributes(): array
    {
        $out = [];
        foreach ($this->attributes as $key => $value) {
            $out[] = ['key' => $key, 'value' => $this->anyValue($key, $value)];
        }

        return $out;
    }

    /**
     * Wrap a PHP scalar in the OTLP AnyValue shape.
     *
     * @param scalar $value
     * @return array<string, mixed>
     */
    private function anyValue(string $key, mixed $value): array
    {
        if (isset($this->intKeys[$key]) || is_int($value)) {
            // CONTRACT: intValue is a STRING, not a JSON number.
            return ['intValue' => (string) (int) $value];
        }
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        return ['stringValue' => (string) $value];
    }
}
