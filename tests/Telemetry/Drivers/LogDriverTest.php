<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

beforeEach(function (): void {
    // Allow any unexpected channel calls (e.g., deprecations channel)
    Log::shouldReceive('channel')->byDefault()->andReturnSelf();
    Log::shouldReceive('warning')->byDefault();
});

it('logs span data when recordSpan is called', function (): void {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->with('Span recorded', Mockery::on(fn ($data): bool => $data['span_id'] === 'test-span-id'
            && $data['operation'] === 'prism.text.asText'
            && isset($data['duration_ms'])
            && $data['has_error'] === false
            && isset($data['attributes'])));

    $driver = new LogDriver('test-channel');
    $spanData = TelemetryTestHelpers::createTextGenerationSpanData();

    $driver->recordSpan($spanData);
});

it('logs error when span has exception', function (): void {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->with('Span recorded', Mockery::on(fn ($data): bool => $data['span_id'] === 'test-span-id'
            && $data['has_error'] === true
            && isset($data['exception'])
            && $data['exception']['class'] === Exception::class
            && $data['exception']['message'] === 'Test exception'));

    $driver = new LogDriver('test-channel');
    $exception = new Exception('Test exception');
    $spanData = TelemetryTestHelpers::createTextGenerationSpanData(exception: $exception);

    $driver->recordSpan($spanData);
});

it('handles different channel configurations', function (): void {
    Log::shouldReceive('channel')
        ->with('default')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once();

    $defaultDriver = new LogDriver;
    $defaultDriver->recordSpan(TelemetryTestHelpers::createTextGenerationSpanData());
});

it('logs attributes extracted from domain objects', function (): void {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->with('Span recorded', Mockery::on(
            fn ($data): bool => $data['attributes']['model'] === 'gpt-4'
            && $data['attributes']['provider'] === 'openai'
            && $data['attributes']['usage']['prompt_tokens'] === 10));

    $driver = new LogDriver('test-channel');
    $spanData = TelemetryTestHelpers::createTextGenerationSpanData();

    $driver->recordSpan($spanData);
});
