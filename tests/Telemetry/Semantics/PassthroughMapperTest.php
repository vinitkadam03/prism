<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Contracts\SemanticMapperInterface;
use Prism\Prism\Telemetry\Semantics\PassthroughMapper;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

it('implements SemanticMapperInterface', function (): void {
    $mapper = new PassthroughMapper;

    expect($mapper)->toBeInstanceOf(SemanticMapperInterface::class);
});

it('returns human-readable attributes from span data', function (): void {
    $mapper = new PassthroughMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData();
    $attrs = $mapper->map($span);

    expect($attrs['model'])->toBe('gpt-4')
        ->and($attrs['provider'])->toBe('openai')
        ->and($attrs['output'])->toBe('Hello there!');
});

it('returns events unchanged', function (): void {
    $mapper = new PassthroughMapper;

    $events = [
        ['name' => 'test', 'timeNanos' => 1000, 'attributes' => ['key' => 'value']],
    ];

    expect($mapper->mapEvents($events))->toBe($events);
});

it('extracts tool call attributes', function (): void {
    $mapper = new PassthroughMapper;

    $span = TelemetryTestHelpers::createToolCallSpanData();
    $attrs = $mapper->map($span);

    expect($attrs['tool']['name'])->toBe('search')
        ->and($attrs['tool']['call_id'])->toBe('call_123')
        ->and($attrs['output'])->toBe('Search results');
});

it('handles empty events', function (): void {
    $mapper = new PassthroughMapper;

    expect($mapper->mapEvents([]))->toBe([]);
});

it('includes metadata in attributes', function (): void {
    $mapper = new PassthroughMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData(
        metadata: ['user_id' => '123', 'session_id' => 'abc']
    );

    $attrs = $mapper->map($span);

    expect($attrs['metadata']['user_id'])->toBe('123')
        ->and($attrs['metadata']['session_id'])->toBe('abc');
});
