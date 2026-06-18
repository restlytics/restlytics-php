<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Otlp\Payload;
use Restlytics\Laravel\Span;
use Restlytics\Laravel\Support\Ids;

/**
 * Guards the wire format against the packages/contract Zod schema rules:
 *  - ids are lowercase hex (32 / 16), never all-zero
 *  - kind is an integer (2 SERVER / 3 CLIENT)
 *  - *UnixNano are decimal STRINGS
 *  - intValue is a STRING (the single most error-prone rule)
 *  - status.code is 0|1|2
 *  - root span has no parentSpanId; children reference the root
 */
final class OtlpPayloadTest extends TestCase
{
    public function test_ids_are_lowercase_hex_and_not_all_zero(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $trace = Ids::traceId();
            $span = Ids::spanId();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $trace);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span);
            $this->assertDoesNotMatchRegularExpression('/^0+$/', $trace);
            $this->assertDoesNotMatchRegularExpression('/^0+$/', $span);
        }
    }

    public function test_server_span_serializes_to_contract_shape(): void
    {
        $span = new Span(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            parentSpanId: null,
            name: 'GET /users/{id}',
            kind: Span::KIND_SERVER,
            startUnixNano: 1_700_000_000_000_000_000,
            endUnixNano: 1_700_000_000_500_000_000,
        );
        $span->setString('http.request.method', 'GET');
        $span->setString('http.route', '/users/{id}');
        $span->setInt('http.response.status_code', 200);
        $span->setInt('restlytics.self_ns.db', 1234);
        $span->setStatus(Span::STATUS_OK);

        $out = $span->toOtlpArray();

        // ids + kind
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $out['traceId']);
        $this->assertSame(2, $out['kind']);
        $this->assertIsInt($out['kind']);
        // root has no parentSpanId
        $this->assertArrayNotHasKey('parentSpanId', $out);

        // *UnixNano are STRINGS
        $this->assertSame('1700000000000000000', $out['startTimeUnixNano']);
        $this->assertSame('1700000000500000000', $out['endTimeUnixNano']);
        $this->assertIsString($out['startTimeUnixNano']);

        // attributes: intValue must be a STRING
        $attrs = $this->attrMap($out['attributes']);
        $this->assertSame(['stringValue' => 'GET'], $attrs['http.request.method']);
        $this->assertSame(['stringValue' => '/users/{id}'], $attrs['http.route']);
        $this->assertSame(['intValue' => '200'], $attrs['http.response.status_code']);
        $this->assertIsString($attrs['http.response.status_code']['intValue']);
        $this->assertSame(['intValue' => '1234'], $attrs['restlytics.self_ns.db']);

        // status
        $this->assertSame(1, $out['status']['code']);
    }

    public function test_client_span_has_parent_and_kind_3(): void
    {
        $span = new Span(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: 'aaaaaaaaaaaaaaaa',
            parentSpanId: '00f067aa0ba902b7',
            name: 'db select',
            kind: Span::KIND_CLIENT,
            startUnixNano: 1_700_000_000_000_000_000,
            endUnixNano: 1_700_000_000_010_000_000,
        );
        $span->setString('restlytics.category', 'db');

        $out = $span->toOtlpArray();
        $this->assertSame(3, $out['kind']);
        $this->assertSame('00f067aa0ba902b7', $out['parentSpanId']);
    }

    public function test_error_status_code_is_two(): void
    {
        $span = new Span('a', 'b', null, 'x', Span::KIND_SERVER, 1, 2);
        $span->setStatus(Span::STATUS_ERROR, 'boom');
        $out = $span->toOtlpArray();
        $this->assertSame(2, $out['status']['code']);
        $this->assertSame('boom', $out['status']['message']);
    }

    public function test_payload_envelope_has_resource_and_scope(): void
    {
        $root = new Span(
            '4bf92f3577b34da6a3ce929d0e0e4736',
            '00f067aa0ba902b7',
            null,
            'GET /',
            Span::KIND_SERVER,
            1_700_000_000_000_000_000,
            1_700_000_000_500_000_000,
        );

        $payload = Payload::build('checkout-api', 'production', [$root]);

        $rs = $payload['resourceSpans'][0];
        $resourceAttrs = $this->attrMap($rs['resource']['attributes']);
        $this->assertSame(['stringValue' => 'checkout-api'], $resourceAttrs['service.name']);
        $this->assertSame(['stringValue' => 'production'], $resourceAttrs['deployment.environment']);
        $this->assertSame(['stringValue' => 'restlytics-laravel'], $resourceAttrs['telemetry.sdk.name']);
        $this->assertSame(['stringValue' => 'php'], $resourceAttrs['telemetry.sdk.language']);
        $this->assertSame(['stringValue' => '0.1.0'], $resourceAttrs['telemetry.sdk.version']);

        $this->assertSame('restlytics-laravel', $rs['scopeSpans'][0]['scope']['name']);
        $this->assertCount(1, $rs['scopeSpans'][0]['spans']);

        // Whole payload must be JSON-encodable (no resources/closures leaked in).
        $json = json_encode($payload);
        $this->assertIsString($json);
        $this->assertStringContainsString('"startTimeUnixNano":"1700000000000000000"', $json);
    }

    /**
     * @param list<array{key: string, value: array<string, mixed>}> $attrs
     * @return array<string, array<string, mixed>>
     */
    private function attrMap(array $attrs): array
    {
        $map = [];
        foreach ($attrs as $kv) {
            $map[$kv['key']] = $kv['value'];
        }

        return $map;
    }
}
