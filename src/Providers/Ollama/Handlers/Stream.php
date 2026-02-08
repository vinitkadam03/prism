<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Concerns\ParsesSSE;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\Providers\Ollama\Maps\ToolMap;
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
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream implements StreamParser
{
    use ParsesSSE;

    public function __construct(protected PendingRequest $client) {}

    public function providerName(): string
    {
        return 'ollama';
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
        $promptTokens = 0;
        $completionTokens = 0;
        $finishReason = FinishReason::Stop;

        while (! $stream->eof()) {
            $data = $this->parseNextChunk($stream);

            if ($data === null) {
                continue;
            }

            $promptTokens += (int) data_get($data, 'prompt_eval_count', 0);
            $completionTokens += (int) data_get($data, 'eval_count', 0);

            $thinking = data_get($data, 'message.thinking', '');
            if ($thinking !== '') {
                $reasoningId ??= EventID::generate();

                yield new ThinkingEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $thinking,
                    reasoningId: $reasoningId,
                );

                continue;
            }

            if ($this->hasToolCalls($data)) {
                foreach ($this->extractToolCalls($data) as $toolCall) {
                    yield new ToolCallEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolCall: $toolCall,
                        messageId: $messageId,
                    );
                }

                $finishReason = FinishReason::ToolCalls;
            }

            $content = data_get($data, 'message.content', '');
            if ($content !== '') {
                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $messageId,
                );
            }
        }

        return new TurnResult(
            finishReason: $finishReason,
            usage: new Usage(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
            ),
            model: $request->model(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseNextChunk(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (in_array(trim($line), ['', '0'], true)) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Ollama', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return (bool) data_get($data, 'message.tool_calls');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ToolCall[]
     */
    protected function extractToolCalls(array $data): array
    {
        $toolCalls = [];

        foreach (data_get($data, 'message.tool_calls', []) as $toolCall) {
            $arguments = data_get($toolCall, 'function.arguments');
            $argumentValue = is_array($arguments) ? json_encode($arguments) : ($arguments ?? '');

            $toolCalls[] = new ToolCall(
                id: data_get($toolCall, 'id') ?? '',
                name: data_get($toolCall, 'function.name') ?? '',
                arguments: $argumentValue,
            );
        }

        return $toolCalls;
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post('api/chat', [
                'model' => $request->model(),
                'messages' => (new MessageMap(array_merge(
                    $request->systemPrompts(),
                    $request->messages()
                )))->map(),
                'tools' => ToolMap::map($request->tools()),
                'stream' => true,
                ...Arr::whereNotNull([
                    'think' => $request->providerOptions('thinking'),
                    'keep_alive' => $request->providerOptions('keep_alive'),
                ]),
                'options' => Arr::whereNotNull(array_merge([
                    'temperature' => $request->temperature(),
                    'num_predict' => $request->maxTokens() ?? 2048,
                    'top_p' => $request->topP(),
                ], $request->providerOptions())),
            ]);

        return $response;
    }
}
