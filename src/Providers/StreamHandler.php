<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Generator;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Streaming\TurnResult;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;

class StreamHandler
{
    use CallsTools;

    protected StreamState $state;

    /** @var ToolCall[] */
    protected array $toolCalls = [];

    /** @var MessagePartWithCitations[] */
    protected array $citations = [];

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(StreamParser $provider, Request $request): Generator
    {
        $this->state = new StreamState;
        $this->state->withModel($request->model());

        yield from $this->processTurn($provider, $request, depth: 0);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processTurn(StreamParser $provider, Request $request, int $depth): Generator
    {
        $stream = $provider->parse($request);

        foreach ($stream as $event) {
            foreach ($this->processEvent($event, $provider) as $wrapped) {
                yield $wrapped;
            }
        }

        /** @var TurnResult $result */
        $result = $stream->getReturn();

        if ($result->usage !== null) {
            $this->state->addUsage($result->usage);
        }

        foreach ($this->completeTurn($provider, $request, $result, $depth) as $event) {
            yield $event;
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processEvent(StreamEvent $event, StreamParser $provider): Generator
    {
        if (! $this->state->hasStreamStarted()) {
            $this->state->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $this->state->model(),
                provider: $provider->providerName(),
            );
        }

        if ($this->state->shouldEmitStepStart()) {
            $this->state->markStepStarted();

            yield new StepStartEvent(
                id: EventID::generate(),
                timestamp: time(),
            );
        }

        if ($event instanceof ThinkingEvent) {
            if ($this->state->shouldEmitThinkingStart()) {
                $this->state
                    ->withReasoningId($event->reasoningId)
                    ->markThinkingStarted();

                yield new ThinkingStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    reasoningId: $event->reasoningId,
                );
            }

            $this->state->appendThinking($event->delta);

            yield $event;

            return;
        }

        if ($event instanceof TextDeltaEvent) {
            if ($this->state->hasThinkingStarted()) {
                $this->state->markThinkingCompleted();

                yield new ThinkingCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    reasoningId: $this->state->reasoningId(),
                );
            }

            // Detect output item boundary (messageId changed)
            if ($this->state->hasTextStarted() && $event->messageId !== $this->state->messageId()) {
                $this->state->markTextCompleted();

                yield new TextCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    messageId: $this->state->messageId(),
                );

                $this->state->withMessageId($event->messageId);
                $this->state->markTextStarted();

                yield new TextStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    messageId: $event->messageId,
                );
            } elseif ($this->state->shouldEmitTextStart()) {
                $this->captureMessageId($event->messageId);
                $this->state->markTextStarted();

                yield new TextStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    messageId: $this->state->messageId(),
                );
            }

            $this->state->appendText($event->delta);

            yield $event;

            return;
        }

        if ($event instanceof ToolCallEvent) {
            $this->captureMessageId($event->messageId);
            $this->toolCalls[] = $event->toolCall;
            yield $event;

            return;
        }

        if ($event instanceof CitationEvent) {
            $this->citations[] = new MessagePartWithCitations(
                outputText: $this->state->currentText(),
                citations: [$event->citation],
            );
            yield $event;

            return;
        }

        yield $event;
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function completeTurn(StreamParser $provider, Request $request, TurnResult $result, int $depth): Generator
    {
        if ($this->state->hasThinkingStarted()) {
            $this->state->markThinkingCompleted();

            yield new ThinkingCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId(),
            );
        }

        if ($this->state->hasTextStarted()) {
            $this->state->markTextCompleted();

            yield new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId(),
            );
        }

        if ($this->toolCalls !== []) {
            if ($depth >= $request->maxSteps()) {
                throw new PrismException('Maximum tool call chain depth exceeded. Increase maxSteps to allow more tool call iterations.');
            }

            foreach ($this->handleToolCalls($provider, $request, $result, $depth) as $event) {
                yield $event;
            }

            return;
        }

        $this->state->markStepFinished();

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
        );

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $result->finishReason ?? FinishReason::Stop,
            usage: $this->state->usage(),
            citations: $this->citations !== [] ? $this->citations : null,
            additionalContent: $result->additionalContent,
        );
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(StreamParser $provider, Request $request, TurnResult $result, int $depth): Generator
    {
        $toolCalls = $this->toolCalls;
        $text = $this->state->currentText();
        $messageId = $this->state->messageId();

        $toolResults = [];
        $hasPendingToolCalls = false;

        foreach ($this->callToolsAndYieldEvents($request->tools(), $toolCalls, $messageId, $toolResults, $hasPendingToolCalls) as $event) {
            yield $event;
        }

        if ($hasPendingToolCalls) {
            $this->state->markStepFinished();
            yield new StepFinishEvent(
                id: EventID::generate(),
                timestamp: time(),
            );

            yield new StreamEndEvent(
                id: EventID::generate(),
                timestamp: time(),
                finishReason: FinishReason::ToolCalls,
                usage: $this->state->usage(),
                citations: $this->citations !== [] ? $this->citations : null,
            );

            return;
        }

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
        );

        $request->addMessage(new AssistantMessage(
            content: $text,
            toolCalls: $toolCalls,
            additionalContent: $result->toolCallAdditionalContent,
        ));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $depth++;

        if ($depth < $request->maxSteps()) {
            $this->resetStateForNextTurn();

            foreach ($this->processTurn($provider, $request, $depth) as $event) {
                yield $event;
            }
        } else {
            yield new StreamEndEvent(
                id: EventID::generate(),
                timestamp: time(),
                finishReason: $result->finishReason ?? FinishReason::Stop,
                usage: $this->state->usage(),
                citations: $this->citations !== [] ? $this->citations : null,
                additionalContent: $result->additionalContent,
            );
        }
    }

    protected function resetStateForNextTurn(): void
    {
        $this->state->reset();
        $this->state->withMessageId(EventID::generate());
        $this->toolCalls = [];
        $this->citations = [];
    }

    protected function captureMessageId(string $messageId): void
    {
        if ($this->state->messageId() === '') {
            $this->state->withMessageId($messageId);
        }
    }
}
