<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Events\StreamStepStarted;
use Prism\Prism\Telemetry\Events\StructuredOutputStarted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Telemetry\Events\TextStepStarted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;

/**
 * Telemetry operation types tracked by span collectors.
 *
 * Each case corresponds to a Prism operation that generates telemetry spans.
 * The string values match the fluent API patterns (e.g., text()->asText()).
 */
enum TelemetryOperation: string
{
    case TextGeneration = 'prism.text.asText';
    case Streaming = 'prism.text.asStream';
    case StructuredOutput = 'prism.structured.asStructured';
    case Embeddings = 'prism.embeddings.asEmbeddings';
    case StreamStep = 'streamStep';
    case TextStep = 'textStep';
    case ToolCall = 'toolCall';

    /**
     * Determine the operation type from a telemetry start event.
     */
    public static function fromStartEvent(
        StreamingStarted|StreamStepStarted|TextStepStarted|ToolCallStarted|TextGenerationStarted|StructuredOutputStarted|EmbeddingGenerationStarted $event
    ): self {
        return match (true) {
            $event instanceof StreamingStarted => self::Streaming,
            $event instanceof StreamStepStarted => self::StreamStep,
            $event instanceof TextStepStarted => self::TextStep,
            $event instanceof ToolCallStarted => self::ToolCall,
            $event instanceof TextGenerationStarted => self::TextGeneration,
            $event instanceof StructuredOutputStarted => self::StructuredOutput,
            $event instanceof EmbeddingGenerationStarted => self::Embeddings,
        };
    }
}
