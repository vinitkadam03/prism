<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Handlers;

use Generator;
use Illuminate\Http\Client\Response;
use Prism\Prism\Providers\ChatCompletionsStreamHandler;
use Prism\Prism\Providers\OpenRouter\Concerns\BuildsRequestOptions;
use Prism\Prism\Providers\OpenRouter\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenRouter\Concerns\ValidatesResponses;
use Prism\Prism\Providers\OpenRouter\Maps\MessageMap;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class Stream extends ChatCompletionsStreamHandler
{
    use BuildsRequestOptions, MapsFinishReason, ValidatesResponses;

    protected function providerName(): string
    {
        return 'openrouter';
    }

    /**
     * Override to extract usage from chunks without finish_reason.
     *
     * OpenRouter may send usage in a separate final chunk (when stream_options.include_usage=true).
     * The parent already handles usage extraction on finish_reason chunks, so we only extract
     * from chunks without finish_reason to avoid double-counting.
     *
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function processChunk(array $data, Request $request): Generator
    {
        $rawFinishReason = data_get($data, 'choices.0.finish_reason');

        if ($rawFinishReason === null) {
            $usage = $this->extractUsage($data);
            if ($usage instanceof Usage) {
                $this->state->addUsage($usage);
            }
        }

        yield from parent::processChunk($data, $request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractThinkingDelta(array $data, Request $request): string
    {
        return data_get($data, 'choices.0.delta.reasoning') ?? '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return ! empty($data['choices'][0]['delta']['tool_calls']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $deltaToolCalls = data_get($data, 'choices.0.delta.tool_calls', []);

        foreach ($deltaToolCalls as $deltaToolCall) {
            $index = $deltaToolCall['index'];

            if (isset($deltaToolCall['id'])) {
                $toolCalls[$index]['id'] = $deltaToolCall['id'];
            }

            if (isset($deltaToolCall['type'])) {
                $toolCalls[$index]['type'] = $deltaToolCall['type'];
            }

            if (isset($deltaToolCall['function'])) {
                if (isset($deltaToolCall['function']['name'])) {
                    $toolCalls[$index]['function']['name'] = $deltaToolCall['function']['name'];
                }

                if (isset($deltaToolCall['function']['arguments'])) {
                    $toolCalls[$index]['function']['arguments'] =
                        ($toolCalls[$index]['function']['arguments'] ?? '').
                        $deltaToolCall['function']['arguments'];
                }
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return ToolCall[]
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(function ($toolCall): ToolCall {
                $arguments = data_get($toolCall, 'function.arguments', '');

                if (is_string($arguments) && $arguments !== '') {
                    try {
                        $parsedArguments = json_decode($arguments, true, flags: JSON_THROW_ON_ERROR);
                        $arguments = $parsedArguments;
                    } catch (Throwable) {
                        $arguments = ['raw' => $arguments];
                    }
                }

                return new ToolCall(
                    data_get($toolCall, 'id'),
                    data_get($toolCall, 'function.name'),
                    $arguments,
                );
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): ?Usage
    {
        $usage = data_get($data, 'usage');

        if (! $usage) {
            return null;
        }

        return new Usage(
            promptTokens: (int) data_get($usage, 'prompt_tokens', 0),
            completionTokens: (int) data_get($usage, 'completion_tokens', 0),
            cacheReadInputTokens: (int) data_get($usage, 'prompt_tokens_details.cached_tokens', 0),
            thoughtTokens: (int) data_get($usage, 'completion_tokens_details.reasoning_tokens', 0)
        );
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_tokens' => $request->maxTokens(),
                ], $this->buildRequestOptions($request, [
                    'stream_options' => ['include_usage' => true],
                ]))
            );

        return $response;
    }
}
