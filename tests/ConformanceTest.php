<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Otlp\Payload;
use Restlytics\Laravel\Span;
use Restlytics\Laravel\Support\Ids;
use Restlytics\Laravel\Tracer;
use Restlytics\Laravel\Transport\NullTransport;

final class ConformanceTest extends TestCase
{
    public function test_shared_otlp_propagation_redaction_error_and_sampling_fixture(): void
    {
        $fixture = $this->properties();
        $span = new Span(
            traceId: $fixture['trace.id'],
            spanId: $fixture['span.id'],
            parentSpanId: $fixture['span.parent_id'],
            name: $fixture['span.name'],
            kind: (int) $fixture['span.kind'],
            startUnixNano: (int) $fixture['span.start_ns'],
            endUnixNano: (int) $fixture['span.end_ns'],
        );
        $span
            ->setString($fixture['attribute.string.key'], $fixture['attribute.string.value'])
            ->setInt($fixture['attribute.int.key'], (int) $fixture['attribute.int.value'])
            ->setBool($fixture['attribute.bool.key'], $fixture['attribute.bool.value'] === 'true')
            ->setString($fixture['redaction.attribute_key'], $fixture['redaction.attribute_value'])
            ->setStatus((int) $fixture['error.status_code'], $fixture['error.message']);

        $expectedJson = file_get_contents(__DIR__.'/Fixtures/v1/otlp.expected.json');
        self::assertIsString($expectedJson);
        $expectedJson = str_replace(
            ['${SDK_NAME}', '${SDK_LANGUAGE}', '${SDK_VERSION}'],
            [Payload::SDK_NAME, Payload::SDK_LANGUAGE, Payload::SDK_VERSION],
            $expectedJson,
        );
        $expected = json_decode($expectedJson, true, flags: JSON_THROW_ON_ERROR);
        self::assertEquals(
            $expected,
            Payload::build($fixture['service.name'], $fixture['deployment.environment'], [$span]),
        );

        $sampled = Ids::parseTraceparent($fixture['propagation.sampled']);
        self::assertSame($fixture['trace.id'], $sampled['traceId']);
        self::assertSame($fixture['span.id'], $sampled['parentSpanId']);
        self::assertTrue($sampled['sampled']);
        self::assertFalse(Ids::parseTraceparent($fixture['propagation.unsampled'])['sampled']);
        self::assertNull(Ids::parseTraceparent($fixture['propagation.invalid']));

        $zero = new Tracer(
            new NullTransport,
            'fixture',
            'fixture',
            (float) $fixture['sampling.root_rate_zero'],
        );
        $zero->startServerSpan('fixture');
        self::assertFalse($zero->isSampled());
        $one = new Tracer(
            new NullTransport,
            'fixture',
            'fixture',
            (float) $fixture['sampling.root_rate_one'],
        );
        $one->startServerSpan('fixture');
        self::assertTrue($one->isSampled());
    }

    /** @return array<string, string> */
    private function properties(): array
    {
        $lines = file(__DIR__.'/Fixtures/v1/vectors.properties', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $values = [];
        foreach ($lines as $line) {
            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }

        return $values;
    }
}
