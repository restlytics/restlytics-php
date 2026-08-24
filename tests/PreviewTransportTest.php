<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Otlp\Payload;
use Restlytics\Laravel\Span;
use Restlytics\Laravel\Transport\PreviewTransport;

final class PreviewTransportTest extends TestCase
{
    public function test_reports_redacted_payload_and_sizes_without_networking(): void
    {
        $output = [];
        $transport = new PreviewTransport(0.25, static function (string $json) use (&$output): void {
            $output[] = $json;
        });
        $span = new Span(str_repeat('a', 32), str_repeat('b', 16), null, 'GET /users/{id}', Span::KIND_SERVER, 1, 2);
        $span
            ->setString('url.full', 'https://user:secret@example.test/users/1?token=secret')
            ->setString('http.request.body', 'do-not-export');

        $transport->send(Payload::build('preview-app', 'production', [$span]));

        self::assertCount(1, $transport->reports);
        $report = $transport->reports[0];
        self::assertFalse($report['networkRequestMade']);
        self::assertSame(0.25, $report['configuredSampleRate']);
        self::assertSame(1, $report['spanCount']);
        self::assertGreaterThan($report['gzipBytes'], $report['jsonBytes']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $encoded);
        self::assertStringNotContainsString('do-not-export', $encoded);
        self::assertStringContainsString('REDACTED', $output[0]);
    }

    public function test_reports_log_batches_without_networking(): void
    {
        $transport = new PreviewTransport(1.0);
        $transport->sendLogs([
            'resourceLogs' => [[
                'scopeLogs' => [[
                    'logRecords' => [[
                        'timeUnixNano' => '1',
                        'severityNumber' => 13,
                        'body' => ['stringValue' => '[REDACTED]'],
                    ]],
                ]],
            ]],
        ]);

        self::assertSame('logs', $transport->reports[0]['signal']);
        self::assertSame(1, $transport->reports[0]['logRecordCount']);
        self::assertSame(0, $transport->reports[0]['spanCount']);
        self::assertFalse($transport->reports[0]['networkRequestMade']);
    }
}
