<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Contracts;

use Prism\Prism\Telemetry\SpanData;

/**
 * Interface for semantic convention mappers.
 *
 * Mappers convert Prism span data to specific semantic conventions
 * like OpenInference (Phoenix/Arize) or GenAI (Langfuse).
 *
 * Mappers receive the full SpanData with access to domain objects
 * (Request, Response, ToolCall, etc.) and can extract whatever
 * attributes they need for their target format.
 */
interface SemanticMapperInterface
{
    /**
     * Map Prism span data to semantic convention format.
     *
     * @param  SpanData  $span  The complete span with domain objects
     * @return array<string, mixed> Mapped attributes in semantic convention format
     */
    public function map(SpanData $span): array;

    /**
     * Map mid-span events to semantic convention format.
     *
     * @param  array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>  $events
     * @return array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>
     */
    public function mapEvents(array $events): array;
}
