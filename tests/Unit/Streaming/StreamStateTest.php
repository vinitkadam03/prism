<?php

declare(strict_types=1);

use Prism\Prism\Streaming\StreamState;
use Prism\Prism\ValueObjects\Usage;

it('constructs with default empty state', function (): void {
    $state = new StreamState;

    expect($state->messageId())->toBe('')
        ->and($state->reasoningId())->toBe('')
        ->and($state->model())->toBe('')
        ->and($state->hasStreamStarted())->toBeFalse()
        ->and($state->hasTextStarted())->toBeFalse()
        ->and($state->hasThinkingStarted())->toBeFalse()
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('')
        ->and($state->usage())->toBeNull();
});

it('withMessageId returns self and sets value', function (): void {
    $state = new StreamState;

    $result = $state->withMessageId('msg-123');

    expect($result)->toBe($state)
        ->and($state->messageId())->toBe('msg-123');
});

it('withReasoningId returns self and sets value', function (): void {
    $state = new StreamState;

    $result = $state->withReasoningId('reason-456');

    expect($result)->toBe($state)
        ->and($state->reasoningId())->toBe('reason-456');
});

it('withModel returns self and sets value', function (): void {
    $state = new StreamState;

    $result = $state->withModel('gpt-4');

    expect($result)->toBe($state)
        ->and($state->model())->toBe('gpt-4');
});

it('markStreamStarted returns self and sets flag', function (): void {
    $state = new StreamState;

    $result = $state->markStreamStarted();

    expect($result)->toBe($state)
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('markStepStarted and markStepFinished toggle flag', function (): void {
    $state = new StreamState;

    expect($state->shouldEmitStepStart())->toBeTrue();

    $state->markStepStarted();
    expect($state->shouldEmitStepStart())->toBeFalse();

    $state->markStepFinished();
    expect($state->shouldEmitStepStart())->toBeTrue();
});

it('markTextStarted returns self and sets flag', function (): void {
    $state = new StreamState;

    $result = $state->markTextStarted();

    expect($result)->toBe($state)
        ->and($state->hasTextStarted())->toBeTrue();
});

it('markTextCompleted returns self and resets flag', function (): void {
    $state = new StreamState;
    $state->markTextStarted();

    $result = $state->markTextCompleted();

    expect($result)->toBe($state)
        ->and($state->hasTextStarted())->toBeFalse();
});

it('markThinkingStarted returns self and sets flag', function (): void {
    $state = new StreamState;

    $result = $state->markThinkingStarted();

    expect($result)->toBe($state)
        ->and($state->hasThinkingStarted())->toBeTrue();
});

it('markThinkingCompleted returns self and resets flag', function (): void {
    $state = new StreamState;
    $state->markThinkingStarted();

    $result = $state->markThinkingCompleted();

    expect($result)->toBe($state)
        ->and($state->hasThinkingStarted())->toBeFalse();
});

it('supports fluent setter chaining', function (): void {
    $state = new StreamState;

    $result = $state
        ->withMessageId('msg-123')
        ->withModel('gpt-4')
        ->markStreamStarted();

    expect($result)->toBe($state)
        ->and($state->messageId())->toBe('msg-123')
        ->and($state->model())->toBe('gpt-4')
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('appendText accumulates text', function (): void {
    $state = new StreamState;

    $state->appendText('Hello');
    $state->appendText(' ');
    $state->appendText('world');

    expect($state->currentText())->toBe('Hello world');
});

it('appendText returns self', function (): void {
    $state = new StreamState;

    $result = $state->appendText('test');

    expect($result)->toBe($state);
});

it('appendThinking accumulates thinking', function (): void {
    $state = new StreamState;

    $state->appendThinking('First thought');
    $state->appendThinking(' and second');

    expect($state->currentThinking())->toBe('First thought and second');
});

it('appendThinking returns self', function (): void {
    $state = new StreamState;

    $result = $state->appendThinking('test');

    expect($result)->toBe($state);
});

it('addUsage stores Usage when none exists', function (): void {
    $state = new StreamState;
    $usage = new Usage(promptTokens: 100, completionTokens: 50);

    $state->addUsage($usage);

    expect($state->usage())->toBe($usage);
});

it('addUsage accumulates tokens across calls', function (): void {
    $state = new StreamState;

    $state->addUsage(new Usage(promptTokens: 100, completionTokens: 50));
    $state->addUsage(new Usage(promptTokens: 200, completionTokens: 100));

    expect($state->usage()->promptTokens)->toBe(300)
        ->and($state->usage()->completionTokens)->toBe(150);
});

it('addUsage accumulates optional token fields', function (): void {
    $state = new StreamState;

    $state->addUsage(new Usage(
        promptTokens: 100,
        completionTokens: 50,
        cacheWriteInputTokens: 25,
        cacheReadInputTokens: 10,
        thoughtTokens: 5
    ));
    $state->addUsage(new Usage(
        promptTokens: 50,
        completionTokens: 25,
        cacheWriteInputTokens: 15,
        cacheReadInputTokens: 5,
        thoughtTokens: 3
    ));

    expect($state->usage()->promptTokens)->toBe(150)
        ->and($state->usage()->completionTokens)->toBe(75)
        ->and($state->usage()->cacheWriteInputTokens)->toBe(40)
        ->and($state->usage()->cacheReadInputTokens)->toBe(15)
        ->and($state->usage()->thoughtTokens)->toBe(8);
});

it('shouldEmitStreamStart returns true when not started', function (): void {
    $state = new StreamState;

    expect($state->shouldEmitStreamStart())->toBeTrue();
});

it('shouldEmitStreamStart returns false when started', function (): void {
    $state = new StreamState;
    $state->markStreamStarted();

    expect($state->shouldEmitStreamStart())->toBeFalse();
});

it('shouldEmitTextStart returns true when not started', function (): void {
    $state = new StreamState;

    expect($state->shouldEmitTextStart())->toBeTrue();
});

it('shouldEmitTextStart returns false when started', function (): void {
    $state = new StreamState;
    $state->markTextStarted();

    expect($state->shouldEmitTextStart())->toBeFalse();
});

it('shouldEmitThinkingStart returns true when not started', function (): void {
    $state = new StreamState;

    expect($state->shouldEmitThinkingStart())->toBeTrue();
});

it('shouldEmitThinkingStart returns false when started', function (): void {
    $state = new StreamState;
    $state->markThinkingStarted();

    expect($state->shouldEmitThinkingStart())->toBeFalse();
});

it('reset clears turn-specific state but preserves streamStarted and usage', function (): void {
    $state = new StreamState;
    $state->withMessageId('msg-123')
        ->withReasoningId('reason-456')
        ->markStreamStarted()
        ->markTextStarted()
        ->markThinkingStarted()
        ->appendText('some text')
        ->appendThinking('some thinking')
        ->addUsage(new Usage(100, 50));

    $state->reset();

    expect($state->messageId())->toBe('')
        ->and($state->reasoningId())->toBe('')
        ->and($state->hasStreamStarted())->toBeTrue()
        ->and($state->hasTextStarted())->toBeFalse()
        ->and($state->hasThinkingStarted())->toBeFalse()
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('')
        ->and($state->usage())->not->toBeNull();
});

it('reset returns self', function (): void {
    $state = new StreamState;

    $result = $state->reset();

    expect($result)->toBe($state);
});
