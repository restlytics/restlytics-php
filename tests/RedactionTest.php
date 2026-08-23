<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Span;
use Restlytics\Laravel\Support\Redaction;

final class RedactionTest extends TestCase
{
    public function test_url_removes_credentials_fragment_and_every_query_value(): void
    {
        $value = Redaction::url(
            'https://alice:password@example.test/orders?token=abc&unknown=customer-secret#raw',
            ['token'],
        );

        foreach (['alice', 'password', 'abc', 'customer-secret', 'raw'] as $secret) {
            $this->assertStringNotContainsString($secret, $value);
        }
    }

    public function test_span_boundary_drops_content_bearing_fields(): void
    {
        $span = new Span(str_repeat('a', 32), str_repeat('b', 16), null, 'GET /users/{id}', Span::KIND_SERVER, 1, 2);
        $span
            ->setString('http.request.method', 'GET')
            ->setString('http.request.header.authorization', 'Bearer abc.def.ghi')
            ->setString('laravel.request.body', 'password=hunter2')
            ->setString('log.body', 'alice@example.test')
            ->setString('url.full', 'https://example.test/?unknown=customer-secret')
            ->setStatus(Span::STATUS_ERROR, 'login failed for alice@example.test password=hunter2');

        $payload = $span->toOtlpArray();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['hunter2', 'alice@example.test', 'customer-secret', 'authorization'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertArrayNotHasKey('message', $payload['status']);
        $this->assertTrue(Redaction::isSensitiveAttributeKey('symfony.request.payload'));
        $this->assertFalse(Redaction::isSensitiveAttributeKey('restlytics.bindings_count'));
    }
}
