<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Otel;

use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Trace\ExtendedSpanProcessorInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;

/**
 * SpanProcessor that maps Prism's `prism.*` attributes to OpenInference semantic conventions.
 *
 * Reads `prism.*` attributes set by the OtlpDriver and adds corresponding
 * OpenInference attributes (`llm.*`, `openinference.*`, etc.) before export.
 *
 * Uses {@see ExtendedSpanProcessorInterface::onEnding()} to modify span attributes
 * while the span is still writable (before it's frozen in `onEnd()`).
 *
 * @see https://github.com/Arize-ai/openinference/blob/main/spec/semantic_conventions.md
 *
 * @experimental ExtendedSpanProcessorInterface is marked experimental in the PHP OTel SDK.
 */
class OpenInferenceBatchSpanProcessor extends BatchSpanProcessor implements ExtendedSpanProcessorInterface
{
    public function __construct(
        SpanExporterInterface $exporter,
        ClockInterface $clock,
    ) {
        parent::__construct($exporter, $clock);
    }

    /**
     * Called before onEnd() while the span is still writable.
     *
     * Reads `prism.*` attributes and adds corresponding OpenInference attributes.
     */
    public function onEnding(ReadWriteSpanInterface $span): void
    {
        $this->addOpenInferenceAttributes($span);
    }

    // ========================================================================
    // Attribute Mapping
    // ========================================================================

    protected function addOpenInferenceAttributes(ReadWriteSpanInterface $span): void
    {
        $operation = $span->getAttribute(PrismSemanticConventions::OPERATION);

        if (! is_string($operation)) {
            return; // Not a Prism span
        }

        match ($operation) {
            'prism.text.asText', 'prism.text.asStream', 'prism.structured.asStructured' => $this->mapChainSpan($span, $operation),
            'textStep', 'streamStep' => $this->mapLlmSpan($span),
            'toolCall' => $this->mapToolSpan($span),
            'prism.embeddings.asEmbeddings' => $this->mapEmbeddingSpan($span),
            default => null,
        };

        $this->mapMetadata($span);
    }

    protected function mapChainSpan(ReadWriteSpanInterface $span, string $operation): void
    {
        $span->setAttribute('openinference.span.kind', 'CHAIN');
        $this->mapCommonLlmAttributes($span);

        if ($operation === 'prism.structured.asStructured') {
            $this->mapStructuredOutput($span);
        } else {
            $this->setOptional($span, 'output.value', PrismSemanticConventions::RESPONSE_TEXT);
            if ($span->getAttribute(PrismSemanticConventions::RESPONSE_TEXT) !== null) {
                $span->setAttribute('output.mime_type', 'text/plain');
            }

            $this->mapOutputMessages($span);
        }
    }

    protected function mapLlmSpan(ReadWriteSpanInterface $span): void
    {
        $span->setAttribute('openinference.span.kind', 'LLM');
        $this->mapCommonLlmAttributes($span);

        $this->setOptional($span, 'output.value', PrismSemanticConventions::RESPONSE_TEXT);
        if ($span->getAttribute(PrismSemanticConventions::RESPONSE_TEXT) !== null) {
            $span->setAttribute('output.mime_type', 'text/plain');
        }

        $this->mapOutputMessages($span);
    }

    protected function mapToolSpan(ReadWriteSpanInterface $span): void
    {
        $span->setAttribute('openinference.span.kind', 'TOOL');

        $this->setOptional($span, 'tool.name', PrismSemanticConventions::TOOL_NAME);

        $toolArgs = $span->getAttribute(PrismSemanticConventions::TOOL_ARGUMENTS);
        if ($toolArgs !== null) {
            $span->setAttribute('tool.parameters', $toolArgs);
            $span->setAttribute('input.value', $toolArgs);
            $span->setAttribute('input.mime_type', 'application/json');
        }

        $toolResult = $span->getAttribute(PrismSemanticConventions::TOOL_RESULT);
        if ($toolResult !== null) {
            $span->setAttribute('output.value', $toolResult);
            $decoded = is_string($toolResult) ? json_decode($toolResult, true) : null;
            $span->setAttribute('output.mime_type', $decoded !== null && $toolResult !== $decoded ? 'application/json' : 'text/plain');
        }
    }

    protected function mapEmbeddingSpan(ReadWriteSpanInterface $span): void
    {
        $span->setAttribute('openinference.span.kind', 'EMBEDDING');

        $this->setOptional($span, 'embedding.model_name', PrismSemanticConventions::MODEL);
        $this->setOptional($span, 'embedding.provider', PrismSemanticConventions::PROVIDER);

        // Per OpenInference spec: embedding.embeddings.{i}.embedding.text
        $inputs = $span->getAttribute(PrismSemanticConventions::EMBEDDING_INPUTS);
        if (is_string($inputs)) {
            /** @var array<int, string>|null $parsedInputs */
            $parsedInputs = json_decode($inputs, true);
            if (is_array($parsedInputs)) {
                foreach ($parsedInputs as $i => $text) {
                    $span->setAttribute("embedding.embeddings.{$i}.embedding.text", $text);
                }

                if (count($parsedInputs) === 1) {
                    $span->setAttribute('input.value', $parsedInputs[0]);
                    $span->setAttribute('input.mime_type', 'text/plain');
                } else {
                    $span->setAttribute('input.value', $inputs);
                    $span->setAttribute('input.mime_type', 'application/json');
                }
            }
        }

        $usageTokens = $span->getAttribute(PrismSemanticConventions::EMBEDDING_USAGE_TOKENS);
        if ($usageTokens !== null) {
            $span->setAttribute('llm.token_count.prompt', $usageTokens);
            $span->setAttribute('llm.token_count.total', $usageTokens);
        }
    }

    // ========================================================================
    // Shared Helpers
    // ========================================================================

    /**
     * Map attributes shared between CHAIN and LLM span kinds.
     */
    protected function mapCommonLlmAttributes(ReadWriteSpanInterface $span): void
    {
        $this->setOptional($span, 'llm.model_name', PrismSemanticConventions::MODEL);
        $this->setOptional($span, 'llm.provider', PrismSemanticConventions::PROVIDER);
        $this->setOptional($span, 'llm.response.model', PrismSemanticConventions::RESPONSE_MODEL);
        $this->setOptional($span, 'llm.response.id', PrismSemanticConventions::RESPONSE_ID);
        $this->setOptional($span, 'llm.service_tier', PrismSemanticConventions::RESPONSE_SERVICE_TIER);
        $this->setOptional($span, 'llm.tool_choice', PrismSemanticConventions::TOOL_CHOICE);

        $this->mapInvocationParameters($span);
        $this->mapInputMessages($span);
        $this->mapToolDefinitions($span);
        $this->mapTokenUsage($span);

        $messages = $span->getAttribute(PrismSemanticConventions::PROMPT_MESSAGES);
        if ($messages !== null) {
            $span->setAttribute('input.value', $messages);
            $span->setAttribute('input.mime_type', 'application/json');
        }
    }

    /**
     * Copy a prism.* attribute to an OpenInference key if it's present.
     */
    protected function setOptional(ReadWriteSpanInterface $span, string $targetKey, string $sourceKey): void
    {
        $value = $span->getAttribute($sourceKey);
        if ($value !== null) {
            $span->setAttribute($targetKey, $value);
        }
    }

    protected function mapInvocationParameters(ReadWriteSpanInterface $span): void
    {
        $params = array_filter([
            'temperature' => $span->getAttribute(PrismSemanticConventions::SETTINGS_TEMPERATURE),
            'max_tokens' => $span->getAttribute(PrismSemanticConventions::SETTINGS_MAX_TOKENS),
            'top_p' => $span->getAttribute(PrismSemanticConventions::SETTINGS_TOP_P),
        ], fn ($v): bool => $v !== null);

        if ($params !== []) {
            $encoded = json_encode($params);
            if ($encoded !== false) {
                $span->setAttribute('llm.invocation_parameters', $encoded);
            }
        }
    }

    protected function mapInputMessages(ReadWriteSpanInterface $span): void
    {
        $messagesJson = $span->getAttribute(PrismSemanticConventions::PROMPT_MESSAGES);
        if (! is_string($messagesJson)) {
            return; // Need a JSON string to decode
        }

        /** @var array<int, array<string, mixed>>|null $messages */
        $messages = json_decode($messagesJson, true);
        if (! is_array($messages)) {
            return;
        }

        foreach ($messages as $i => $msg) {
            $span->setAttribute("llm.input_messages.{$i}.message.role", $msg['role'] ?? '');
            $span->setAttribute("llm.input_messages.{$i}.message.content", $msg['content'] ?? '');

            foreach ($msg['tool_calls'] ?? [] as $j => $tc) {
                $prefix = "llm.input_messages.{$i}.message.tool_calls.{$j}.tool_call";
                if (isset($tc['id'])) {
                    $span->setAttribute("{$prefix}.id", $tc['id']);
                }
                $span->setAttribute("{$prefix}.function.name", $tc['name'] ?? '');
                $encoded = json_encode($tc['arguments'] ?? []);
                $span->setAttribute("{$prefix}.function.arguments", $encoded !== false ? $encoded : '{}');
            }
        }
    }

    protected function mapOutputMessages(ReadWriteSpanInterface $span): void
    {
        $responseText = $span->getAttribute(PrismSemanticConventions::RESPONSE_TEXT);
        $toolCallsJson = $span->getAttribute(PrismSemanticConventions::RESPONSE_TOOL_CALLS);

        if ($responseText === null && $toolCallsJson === null) {
            return;
        }

        $span->setAttribute('llm.output_messages.0.message.role', 'assistant');
        $span->setAttribute('llm.output_messages.0.message.content', $responseText ?? '');

        $this->setOptional($span, 'llm.output_messages.0.message.finish_reason', PrismSemanticConventions::RESPONSE_FINISH_REASON);

        if (is_string($toolCallsJson)) {
            /** @var array<int, array<string, mixed>>|null $toolCalls */
            $toolCalls = json_decode($toolCallsJson, true);
            if (is_array($toolCalls)) {
                foreach ($toolCalls as $i => $tc) {
                    $prefix = "llm.output_messages.0.message.tool_calls.{$i}.tool_call";
                    if (isset($tc['id'])) {
                        $span->setAttribute("{$prefix}.id", $tc['id']);
                    }
                    $span->setAttribute("{$prefix}.function.name", $tc['name'] ?? '');
                    $encoded = json_encode($tc['arguments'] ?? []);
                    $span->setAttribute("{$prefix}.function.arguments", $encoded !== false ? $encoded : '{}');
                }
            }
        }
    }

    protected function mapToolDefinitions(ReadWriteSpanInterface $span): void
    {
        $toolsJson = $span->getAttribute(PrismSemanticConventions::TOOLS);
        if (! is_string($toolsJson)) {
            return;
        }

        /** @var array<int, array<string, mixed>>|null $tools */
        $tools = json_decode($toolsJson, true);
        if (! is_array($tools)) {
            return;
        }

        foreach ($tools as $i => $tool) {
            $encoded = json_encode($tool);
            if ($encoded !== false) {
                $span->setAttribute("llm.tools.{$i}.tool.json_schema", $encoded);
            }
        }
    }

    protected function mapTokenUsage(ReadWriteSpanInterface $span): void
    {
        $promptTokens = $span->getAttribute(PrismSemanticConventions::USAGE_PROMPT_TOKENS);
        $completionTokens = $span->getAttribute(PrismSemanticConventions::USAGE_COMPLETION_TOKENS);

        if ($promptTokens !== null) {
            $span->setAttribute('llm.token_count.prompt', $promptTokens);
        }
        if ($completionTokens !== null) {
            $span->setAttribute('llm.token_count.completion', $completionTokens);
        }
        if ($promptTokens !== null && $completionTokens !== null) {
            $span->setAttribute('llm.token_count.total', $promptTokens + $completionTokens);
        }
    }

    protected function mapStructuredOutput(ReadWriteSpanInterface $span): void
    {
        $this->setOptional($span, 'output.schema.name', PrismSemanticConventions::SCHEMA_NAME);
        $this->setOptional($span, 'output.schema', PrismSemanticConventions::SCHEMA_DEFINITION);

        $responseObject = $span->getAttribute(PrismSemanticConventions::RESPONSE_OBJECT);
        if ($responseObject !== null) {
            $span->setAttribute('output.value', $responseObject);
            $span->setAttribute('output.mime_type', 'application/json');
        }

        $this->mapOutputMessages($span);
    }

    protected function mapMetadata(ReadWriteSpanInterface $span): void
    {
        $keysJson = $span->getAttribute(PrismSemanticConventions::METADATA_KEYS);
        if (! is_string($keysJson)) {
            return;
        }

        /** @var array<int, string>|null $keys */
        $keys = json_decode($keysJson, true);
        if (! is_array($keys)) {
            return;
        }

        foreach ($keys as $metaKey) {
            $value = $span->getAttribute(PrismSemanticConventions::METADATA_PREFIX.$metaKey);
            if ($value === null) {
                continue;
            }

            match ($metaKey) {
                'user_id' => $span->setAttribute('user.id', (string) $value),
                'session_id' => $span->setAttribute('session.id', (string) $value),
                'agent' => $span->setAttribute('agent.name', (string) $value),
                'tags' => $this->mapTags($span, $value),
                default => $span->setAttribute("metadata.{$metaKey}", $value),
            };
        }
    }

    protected function mapTags(ReadWriteSpanInterface $span, mixed $tagsValue): void
    {
        if (is_string($tagsValue)) {
            /** @var array<string|int, string>|null $tags */
            $tags = json_decode($tagsValue, true);
        } elseif (is_array($tagsValue)) {
            $tags = $tagsValue;
        } else {
            return;
        }

        if (! is_array($tags)) {
            return;
        }

        $tagList = [];
        foreach ($tags as $tagKey => $tagValue) {
            $tagList[] = is_int($tagKey)
                ? (string) $tagValue
                : "{$tagKey}:{$tagValue}";
        }

        $encoded = json_encode($tagList);
        if ($encoded !== false) {
            $span->setAttribute('tag.tags', $encoded);
        }
    }
}
