<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Concerns\ParsesSSE;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\StreamParser;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\TurnResult;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

class Stream implements StreamParser
{
    use BuildsTools;
    use ParsesSSE;
    use ProcessRateLimits;

    public function __construct(protected PendingRequest $client) {}

    public function providerName(): string
    {
        return 'openai';
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
        $thinkingSummaries = [];
        $reasoningItems = [];
        $toolCalls = [];
        $finishReason = null;
        $usage = null;
        $model = $request->model();
        $responseId = null;

        while (! $stream->eof()) {
            $data = $this->parseSSEDataLine($stream);

            if ($data === null) {
                continue;
            }

            $type = data_get($data, 'type', '');

            if ($type === 'error') {
                $this->handleError($data, $request);
            }

            if ($type === 'response.created') {
                $model = data_get($data, 'response.model', $model);

                continue;
            }

            if ($type === 'response.reasoning_summary_text.delta') {
                $delta = (string) data_get($data, 'delta', '');

                if ($delta !== '') {
                    $reasoningId ??= EventID::generate();
                    $thinkingSummaries[] = $delta;

                    yield new ThinkingEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        delta: $delta,
                        reasoningId: $reasoningId,
                    );
                }

                continue;
            }

            if ($type === 'response.output_item.done') {
                $item = data_get($data, 'item', []);
                $itemType = data_get($item, 'type', '');

                if ($itemType === 'reasoning') {
                    $index = (int) data_get($data, 'output_index', count($reasoningItems));
                    $reasoningItems[$index] = [
                        'id' => data_get($item, 'id'),
                        'summary' => data_get($item, 'summary', []),
                    ];

                    continue;
                }

                if ($itemType !== 'function_call' && str_ends_with((string) $itemType, '_call')) {
                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $itemType,
                        status: 'completed',
                        itemId: data_get($item, 'id', ''),
                        data: $item,
                    );

                    continue;
                }
            }

            if ($type === 'response.output_item.added' && data_get($data, 'item.type') === 'function_call') {
                $index = (int) data_get($data, 'output_index', count($toolCalls));

                $toolCall = [
                    'id' => data_get($data, 'item.id'),
                    'call_id' => data_get($data, 'item.call_id'),
                    'name' => data_get($data, 'item.name'),
                    'arguments' => '',
                ];

                if ($reasoningItems !== []) {
                    $latestReasoning = end($reasoningItems);
                    $toolCall['reasoning_id'] = $latestReasoning['id'];
                    $toolCall['reasoning_summary'] = $latestReasoning['summary'] ?? [];
                }

                $toolCalls[$index] = $toolCall;

                continue;
            }

            if ($type === 'response.function_call_arguments.delta') {
                $callId = data_get($data, 'item_id');
                $delta = data_get($data, 'delta', '');

                foreach ($toolCalls as $index => $call) {
                    if (($call['id'] ?? null) === $callId) {
                        $toolCalls[$index]['arguments'] = ($toolCalls[$index]['arguments'] ?? '').$delta;

                        yield new ToolCallDeltaEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            toolId: $call['id'],
                            toolName: $call['name'],
                            delta: $delta,
                            messageId: $messageId,
                        );

                        break;
                    }
                }

                continue;
            }

            if ($type === 'response.function_call_arguments.done') {
                $callId = data_get($data, 'item_id');
                $arguments = data_get($data, 'arguments', '');

                foreach ($toolCalls as $index => $call) {
                    if (($call['id'] ?? null) === $callId) {
                        if ($arguments !== '') {
                            $toolCalls[$index]['arguments'] = $arguments;
                        }

                        yield new ToolCallEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            toolCall: new ToolCall(
                                id: $call['id'],
                                name: $call['name'],
                                arguments: $toolCalls[$index]['arguments'],
                                resultId: $call['call_id'] ?? null,
                                reasoningId: $call['reasoning_id'] ?? null,
                                reasoningSummary: $call['reasoning_summary'] ?? [],
                            ),
                            messageId: $messageId,
                        );

                        break;
                    }
                }

                continue;
            }

            if (str_starts_with((string) $type, 'response.') && str_contains((string) $type, '_call.')) {
                $parts = explode('.', (string) $type, 3);

                if (count($parts) === 3 && str_ends_with($parts[1], '_call')) {
                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $parts[1],
                        status: $parts[2],
                        itemId: data_get($data, 'item_id', ''),
                        data: $data,
                    );

                    continue;
                }
            }

            if ($type === 'response.output_text.delta') {
                $content = (string) data_get($data, 'delta', '');

                if ($content !== '') {
                    yield new TextDeltaEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        delta: $content,
                        messageId: data_get($data, 'item_id', $messageId),
                    );
                }

                continue;
            }

            if ($type === 'response.completed') {
                $finishReason = $this->mapFinishReasonFromData($data);
                $responseId = data_get($data, 'response.id');
                $usage = new Usage(
                    promptTokens: data_get($data, 'response.usage.input_tokens'),
                    completionTokens: data_get($data, 'response.usage.output_tokens'),
                    cacheReadInputTokens: data_get($data, 'response.usage.input_tokens_details.cached_tokens'),
                    thoughtTokens: data_get($data, 'response.usage.output_tokens_details.reasoning_tokens'),
                );
            }
        }

        return new TurnResult(
            finishReason: $finishReason,
            usage: $usage,
            model: $model,
            additionalContent: Arr::whereNotNull([
                'response_id' => $responseId,
                'reasoningSummaries' => $thinkingSummaries !== [] ? $thinkingSummaries : null,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleError(array $data, Request $request): never
    {
        $code = data_get($data, 'error.code', 'unknown_error');
        $message = data_get($data, 'error.message', 'No error message provided');

        if ($code === 'rate_limit_exceeded') {
            throw new PrismRateLimitedException([]);
        }

        throw new PrismException(sprintf(
            'Sending to model %s failed. Code: %s. Message: %s',
            $request->model(),
            $code,
            $message,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReasonFromData(array $data): FinishReason
    {
        $eventType = Str::after(data_get($data, 'type'), 'response.');
        $lastOutputType = data_get($data, 'response.output.{last}.type');

        return FinishReasonMap::map($eventType, $lastOutputType);
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'responses',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                ], Arr::whereNotNull([
                    'max_output_tokens' => $request->maxTokens(),
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'metadata' => $request->providerOptions('metadata'),
                    'tools' => $this->buildTools($request),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
                    'previous_response_id' => $request->providerOptions('previous_response_id'),
                    'service_tier' => $request->providerOptions('service_tier'),
                    'text' => $request->providerOptions('text_verbosity') ? [
                        'verbosity' => $request->providerOptions('text_verbosity'),
                    ] : null,
                    'truncation' => $request->providerOptions('truncation'),
                    'reasoning' => $request->providerOptions('reasoning'),
                ]))
            );

        return $response;
    }
}
