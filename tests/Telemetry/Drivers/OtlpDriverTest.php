<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\Drivers\OtlpDriver;
use Prism\Prism\Telemetry\Otel\PrimedIdGenerator;
use Prism\Prism\Telemetry\Otel\PrismSemanticConventions;
use Prism\Prism\Telemetry\SpanData;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

describe('ID Preservation', function (): void {
    it('uses exact span ID from SpanData', function (): void {
        $spanId = bin2hex(random_bytes(8));
        $traceId = bin2hex(random_bytes(16));

        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        });

        $startEvent = TelemetryTestHelpers::createTextGenerationStarted($spanId, $traceId);
        $endEvent = TelemetryTestHelpers::createTextGenerationCompleted($spanId, $traceId);

        $driver->recordSpan(new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: null,
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: hrtime(true),
            endTimeNano: hrtime(true) + 1_000_000,
            startEvent: $startEvent,
            endEvent: $endEvent,
        ));

        $driver->shutdown();

        expect($exportedSpans)->toHaveCount(1)
            ->and($exportedSpans[0]->getSpanId())->toBe($spanId)
            ->and($exportedSpans[0]->getTraceId())->toBe($traceId);
    });

    it('preserves parent span ID in context', function (): void {
        $spanId = bin2hex(random_bytes(8));
        $traceId = bin2hex(random_bytes(16));
        $parentSpanId = bin2hex(random_bytes(8));

        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        });

        $startEvent = TelemetryTestHelpers::createTextGenerationStarted($spanId, $traceId, $parentSpanId);
        $endEvent = TelemetryTestHelpers::createTextGenerationCompleted($spanId, $traceId, $parentSpanId);

        $driver->recordSpan(new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: hrtime(true),
            endTimeNano: hrtime(true) + 1_000_000,
            startEvent: $startEvent,
            endEvent: $endEvent,
        ));

        $driver->shutdown();

        expect($exportedSpans[0]->getParentSpanId())->toBe($parentSpanId);
    });
});

describe('SDK Batching', function (): void {
    it('batches multiple spans via SDK BatchSpanProcessor', function (): void {
        $exportCount = 0;
        $totalSpans = 0;

        $driver = createTestableOtlpDriver(function ($spans) use (&$exportCount, &$totalSpans): void {
            $exportCount++;
            $totalSpans += count(iterator_to_array($spans));
        });

        $driver->recordSpan(createOtlpTestSpanData());
        $driver->recordSpan(createOtlpTestSpanData());
        $driver->recordSpan(createOtlpTestSpanData());

        $driver->shutdown();

        // SDK batches spans - typically one export call
        expect($totalSpans)->toBe(3);
    });

    it('flushes all spans on shutdown', function (): void {
        $exportCount = 0;

        $driver = createTestableOtlpDriver(function () use (&$exportCount): void {
            $exportCount++;
        });

        $driver->recordSpan(createOtlpTestSpanData());
        $driver->recordSpan(createOtlpTestSpanData());

        $driver->shutdown();

        // All spans should be exported
        expect($exportCount)->toBe(2);

        // Second shutdown should not export anything
        $driver->shutdown();
        expect($exportCount)->toBe(2);
    });
});

describe('Prism Attributes', function (): void {
    it('sets prism.* attributes on exported spans', function (): void {
        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        });

        $driver->recordSpan(createOtlpTestSpanData());
        $driver->shutdown();

        $attrs = $exportedSpans[0]->getAttributes();
        expect($attrs->get(PrismSemanticConventions::OPERATION))->toBe('prism.text.asText')
            ->and($attrs->get(PrismSemanticConventions::MODEL))->toBe('gpt-4')
            ->and($attrs->get(PrismSemanticConventions::PROVIDER))->toBe('openai')
            ->and($attrs->get(PrismSemanticConventions::RESPONSE_TEXT))->toBe('Hello there!')
            ->and($attrs->get(PrismSemanticConventions::USAGE_PROMPT_TOKENS))->toBe(10)
            ->and($attrs->get(PrismSemanticConventions::USAGE_COMPLETION_TOKENS))->toBe(5);
    });
});

describe('Error Handling', function (): void {
    it('sets error status for failed spans', function (): void {
        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        });

        $spanId = bin2hex(random_bytes(8));
        $traceId = bin2hex(random_bytes(16));
        $startEvent = TelemetryTestHelpers::createTextGenerationStarted($spanId, $traceId);
        $endEvent = TelemetryTestHelpers::createTextGenerationCompleted($spanId, $traceId);

        $driver->recordSpan(new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: null,
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: hrtime(true),
            endTimeNano: hrtime(true) + 1_000_000,
            startEvent: $startEvent,
            endEvent: $endEvent,
            exception: new RuntimeException('Something failed'),
        ));

        $driver->shutdown();

        expect($exportedSpans[0]->getStatus()->getCode())->toBe('Error')
            ->and($exportedSpans[0]->getStatus()->getDescription())->toBe('Something failed');
    });
});

describe('Driver Identity', function (): void {
    it('returns configured driver name', function (): void {
        expect((new OtlpDriver('phoenix'))->getDriver())->toBe('phoenix');
    });

    it('defaults to otlp', function (): void {
        expect((new OtlpDriver)->getDriver())->toBe('otlp');
    });
});

describe('Configuration', function (): void {
    it('applies tags from config as prism.metadata.tags', function (): void {
        config(['prism.telemetry.drivers.tagged' => [
            'tags' => ['env' => 'testing'],
        ]]);

        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        }, 'tagged');

        $driver->recordSpan(createOtlpTestSpanData());
        $driver->shutdown();

        // Tags are stored as prism.metadata.tags (JSON encoded)
        $metadataTags = $exportedSpans[0]->getAttributes()->get(PrismSemanticConventions::METADATA_TAGS);
        expect($metadataTags)->toBeString()
            ->and($metadataTags)->toContain('testing');
    });
});

describe('PrimedIdGenerator Integration', function (): void {
    it('uses PrimedIdGenerator for ID generation', function (): void {
        $driver = new OtlpDriver('otlp');

        $ref = new ReflectionMethod($driver, 'idGenerator');
        $generator = $ref->invoke($driver);

        expect($generator)->toBeInstanceOf(PrimedIdGenerator::class);
    });
});

function createTestableOtlpDriver(callable $onExport, string $driverName = 'otlp'): OtlpDriver
{
    $driver = new OtlpDriver($driverName);

    // Create mock exporter
    $mockExporter = Mockery::mock(SpanExporterInterface::class);
    $mockExporter->shouldReceive('export')
        ->andReturnUsing(function ($spans) use ($onExport) {
            $onExport($spans);

            $future = Mockery::mock(FutureInterface::class);
            $future->shouldReceive('await')->andReturn(true);

            return $future;
        });
    $mockExporter->shouldReceive('shutdown')->andReturn(true);

    // Create TracerProvider with mock exporter but real PrimedIdGenerator
    $idGenerator = new PrimedIdGenerator;
    $provider = new TracerProvider(
        spanProcessors: [new SimpleSpanProcessor($mockExporter)],
        sampler: new AlwaysOnSampler,
        resource: ResourceInfo::create(
            Attributes::create(['service.name' => 'test'])
        ),
        idGenerator: $idGenerator,
    );

    // Inject via reflection
    $providerRef = new ReflectionProperty($driver, 'provider');
    $providerRef->setValue($driver, $provider);

    $idGenRef = new ReflectionProperty($driver, 'idGenerator');
    $idGenRef->setValue($driver, $idGenerator);

    return $driver;
}

function createOtlpTestSpanData(): SpanData
{
    $spanId = bin2hex(random_bytes(8));
    $traceId = bin2hex(random_bytes(16));

    return new SpanData(
        spanId: $spanId,
        traceId: $traceId,
        parentSpanId: null,
        operation: TelemetryOperation::TextGeneration,
        startTimeNano: hrtime(true),
        endTimeNano: hrtime(true) + 1_000_000,
        startEvent: TelemetryTestHelpers::createTextGenerationStarted($spanId, $traceId),
        endEvent: TelemetryTestHelpers::createTextGenerationCompleted($spanId, $traceId),
    );
}
