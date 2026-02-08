<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Concerns\ParsesSSE;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Providers\StreamParser;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\TurnResult;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

class Stream implements StreamParser
{
    use ParsesSSE;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey,
    ) {}

    public function providerName(): string
    {
        return 'gemini';
    }

    /**
     * @return Generator<int, StreamEvent, mixed, TurnResult>
     */
    public function parse(Request $request): Generator
    {
        $response = $this->sendRequest($request);
        $stream = $response->getBody();

        $messageId = EventID::generate();
        $reasoningId = null;
        $currentThoughtSignature = null;
        $thinkingSummaries = [];
        $finishReason = FinishReason::Unknown;
        $usage = null;
        $groundingMetadata = null;

        while (! $stream->eof()) {
            $data = $this->parseSSEDataLine($stream);

            if ($data === null) {
                continue;
            }

            $usage = $this->extractUsage($data, $request);

            if ($this->hasToolCalls($data)) {
                $parts = data_get($data, 'candidates.0.content.parts', []);

                foreach ($parts as $part) {
                    if (! isset($part['functionCall'])) {
                        continue;
                    }

                    if (isset($part['thoughtSignature'])) {
                        $currentThoughtSignature = $part['thoughtSignature'];
                    }

                    $arguments = data_get($part, 'functionCall.args', []);

                    if (is_string($arguments) && $arguments !== '') {
                        $decoded = json_decode($arguments, true);
                        $arguments = json_last_error() === JSON_ERROR_NONE ? $decoded : ['input' => $arguments];
                    }

                    yield new ToolCallEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolCall: new ToolCall(
                            id: EventID::generate('gm'),
                            name: data_get($part, 'functionCall.name', 'unknown'),
                            arguments: $arguments,
                            reasoningId: $part['thoughtSignature'] ?? $currentThoughtSignature,
                        ),
                        messageId: $messageId,
                    );
                }

                $finishReason = $this->mapFinishReason($data);

                continue;
            }

            $parts = data_get($data, 'candidates.0.content.parts', []);

            foreach ($parts as $part) {
                if (isset($part['thought']) && $part['thought'] === true) {
                    $thinkingContent = $part['text'] ?? '';

                    if ($thinkingContent !== '') {
                        $reasoningId ??= EventID::generate();
                        $thinkingSummaries[] = $thinkingContent;

                        yield new ThinkingEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: $thinkingContent,
                            reasoningId: $reasoningId,
                        );
                    }
                } elseif (isset($part['text']) && (! isset($part['thought']) || $part['thought'] === false)) {
                    $content = $part['text'];

                    if ($content !== '') {
                        yield new TextDeltaEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: $content,
                            messageId: $messageId,
                        );
                    }
                }
            }

            $chunkFinishReason = $this->mapFinishReason($data);
            if ($chunkFinishReason !== FinishReason::Unknown) {
                $finishReason = $chunkFinishReason;
                $groundingMetadata = $this->extractGroundingMetadata($data);
            }
        }

        return new TurnResult(
            finishReason: $finishReason,
            usage: $usage,
            model: data_get($data ?? [], 'modelVersion', $request->model()),
            additionalContent: Arr::whereNotNull([
                'grounding_metadata' => $groundingMetadata,
                'thoughtSummaries' => $thinkingSummaries !== [] ? $thinkingSummaries : null,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data, Request $request): Usage
    {
        $providerOptions = $request->providerOptions();

        return new Usage(
            promptTokens: isset($providerOptions['cachedContentName'])
                ? (data_get($data, 'usageMetadata.promptTokenCount', 0) - data_get($data, 'usageMetadata.cachedContentTokenCount', 0))
                : data_get($data, 'usageMetadata.promptTokenCount', 0),
            completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
            cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount'),
            thoughtTokens: data_get($data, 'usageMetadata.thoughtsTokenCount'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'candidates.0.finishReason');

        if (! $finishReason) {
            return FinishReason::Unknown;
        }

        return FinishReasonMap::map($finishReason, $this->hasToolCalls($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function extractGroundingMetadata(array $data): ?array
    {
        return data_get($data, 'candidates.0.groundingMetadata');
    }

    protected function sendRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();

        if ($request->tools() !== [] && $request->providerTools() !== []) {
            throw new PrismException('Use of provider tools with custom tools is not currently supported by Gemini.');
        }

        if ($request->tools() !== [] && ($providerOptions['searchGrounding'] ?? false)) {
            throw new PrismException('Use of search grounding with custom tools is not currently supported by Prism.');
        }

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = array_map(
                fn ($providerTool): array => [
                    $providerTool->type => $providerTool->options !== [] ? $providerTool->options : (object) [],
                ],
                $request->providerTools()
            );
        } elseif ($providerOptions['searchGrounding'] ?? false) {
            $tools = [
                [
                    'google_search' => (object) [],
                ],
            ];
        } elseif ($request->tools() !== []) {
            $tools = ['function_declarations' => ToolMap::map($request->tools())];
        }

        $thinkingConfig = $providerOptions['thinkingConfig'] ?? null;

        if (isset($providerOptions['thinkingBudget'])) {
            $thinkingConfig = [
                'thinkingBudget' => $providerOptions['thinkingBudget'],
                'includeThoughts' => true,
            ];
        }

        if (isset($providerOptions['thinkingLevel'])) {
            $thinkingConfig = [
                'thinkingLevel' => $providerOptions['thinkingLevel'],
                'includeThoughts' => true,
            ];
        }

        /** @var Response $response */
        $response = $this->client
            ->withOptions(['stream' => true])
            ->post(
                "{$request->model()}:streamGenerateContent?alt=sse",
                Arr::whereNotNull([
                    ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'cachedContent' => $providerOptions['cachedContentName'] ?? null,
                    'generationConfig' => Arr::whereNotNull([
                        'temperature' => $request->temperature(),
                        'topP' => $request->topP(),
                        'maxOutputTokens' => $request->maxTokens(),
                        'thinkingConfig' => $thinkingConfig,
                    ]) ?: null,
                    'tools' => $tools !== [] ? $tools : null,
                    'tool_config' => $request->toolChoice() ? ToolChoiceMap::map($request->toolChoice()) : null,
                    'safetySettings' => $providerOptions['safetySettings'] ?? null,
                ])
            );

        return $response;
    }
}
