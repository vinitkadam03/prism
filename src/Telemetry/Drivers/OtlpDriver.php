<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Illuminate\Contracts\Container\Container;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Otel\PrimedIdGenerator;
use Prism\Prism\Telemetry\Otel\PrismSpanAttributes;
use Prism\Prism\Telemetry\SpanData;

class OtlpDriver implements TelemetryDriver
{
    protected ?TracerProvider $provider = null;

    protected ?PrimedIdGenerator $idGenerator = null;

    /** @var array<string, mixed> */
    protected array $config;

    public function __construct(
        protected string $driver = 'otlp',
        protected ?Container $container = null,
    ) {
        $this->config = config("prism.telemetry.drivers.{$this->driver}", []);
        $this->container = $container ?? app();
    }

    public function recordSpan(SpanData $spanData): void
    {
        $this->idGenerator()->primeSpanId($spanData->spanId);

        if (! $spanData->parentSpanId) {
            $this->idGenerator()->primeTraceId($spanData->traceId);
        }

        $spanBuilder = $this->provider()->getTracer('prism', '1.0.0')
            ->spanBuilder($spanData->operation->value)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setStartTimestamp($spanData->startTimeNano);

        if ($spanData->parentSpanId) {
            $spanBuilder->setParent(Context::getCurrent()->withContextValue(
                Span::wrap(SpanContext::create($spanData->traceId, $spanData->parentSpanId, TraceFlags::SAMPLED))
            ));
        }

        $span = $spanBuilder->startSpan();

        // Set prism.* attributes -- the SpanProcessor will remap these
        // to the target convention (OpenInference, GenAI, etc.) in onEnding()
        $driverMetadata = ($tags = $this->config['tags'] ?? null) ? ['tags' => $tags] : [];
        $attributes = PrismSpanAttributes::extract($spanData, $driverMetadata);

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        // Add span events (exceptions, etc.) in standard OTel format
        foreach ($spanData->events as $event) {
            $eventAttributes = $event['name'] === 'exception' ? [
                'exception.type' => $event['attributes']['type'] ?? 'Unknown',
                'exception.message' => $event['attributes']['message'] ?? '',
                'exception.stacktrace' => $event['attributes']['stacktrace'] ?? '',
                'exception.escaped' => true,
            ] : $event['attributes'];

            $span->addEvent($event['name'], Attributes::create($eventAttributes), $event['timeNanos']);
        }

        if ($spanData->hasError()) {
            $span->setStatus(StatusCode::STATUS_ERROR, $spanData->exception?->getMessage() ?? 'Error');
        }

        $span->end($spanData->endTimeNano);
    }

    public function shutdown(): void
    {
        $this->provider?->shutdown();
        $this->provider = null;
        $this->idGenerator = null;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    protected function provider(): TracerProvider
    {
        if ($this->provider instanceof TracerProvider) {
            return $this->provider;
        }

        $transport = (new OtlpHttpTransportFactory)->create(
            endpoint: $this->config['endpoint'] ?? 'http://localhost:4318/v1/traces',
            contentType: $this->config['transport_content_type'] ?? ContentTypes::PROTOBUF,
            headers: ($key = $this->config['api_key'] ?? null) ? ['Authorization' => "Bearer {$key}"] : [],
            timeout: (float) ($this->config['timeout'] ?? 30.0),
        );

        // @phpstan-ignore argument.type (TransportInterface generic type variance issue with OpenTelemetry)
        $exporter = new SpanExporter($transport);

        return $this->provider = new TracerProvider(
            spanProcessors: [$this->createSpanProcessor($exporter)],
            sampler: new AlwaysOnSampler,
            resource: ResourceInfo::create(Attributes::create(array_merge(
                ['service.name' => $this->config['service_name'] ?? 'prism'],
                $this->config['resource_attributes'] ?? [],
            ))),
            idGenerator: $this->idGenerator(),
        );
    }

    /**
     * Create the span processor from config.
     *
     * The `span_processor` config accepts a class-string of a SpanProcessorInterface
     * implementation. The class is resolved through the container with the exporter
     * and clock injected, so users get full DI support.
     *
     * For custom construction logic, bind the class in a ServiceProvider.
     *
     * Defaults to BatchSpanProcessor when not configured.
     */
    protected function createSpanProcessor(SpanExporterInterface $exporter): SpanProcessorInterface
    {
        /** @var class-string<SpanProcessorInterface>|null $processorClass */
        $processorClass = $this->config['span_processor'] ?? null;

        if ($processorClass === null) {
            return new BatchSpanProcessor($exporter, Clock::getDefault());
        }

        return $this->container->make($processorClass, [
            'exporter' => $exporter,
            'clock' => Clock::getDefault(),
        ]);
    }

    protected function idGenerator(): PrimedIdGenerator
    {
        return $this->idGenerator ??= new PrimedIdGenerator;
    }
}
