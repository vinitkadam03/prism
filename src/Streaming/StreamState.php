<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Prism\Prism\ValueObjects\Usage;

class StreamState
{
    protected string $messageId = '';

    protected string $reasoningId = '';

    protected string $model = '';

    protected bool $streamStarted = false;

    protected bool $stepStarted = false;

    protected bool $textStarted = false;

    protected bool $thinkingStarted = false;

    protected string $currentText = '';

    protected string $currentThinking = '';

    protected ?Usage $usage = null;

    public function withMessageId(string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function withReasoningId(string $reasoningId): self
    {
        $this->reasoningId = $reasoningId;

        return $this;
    }

    public function withModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function markStreamStarted(): self
    {
        $this->streamStarted = true;

        return $this;
    }

    public function markStepStarted(): self
    {
        $this->stepStarted = true;

        return $this;
    }

    public function markStepFinished(): self
    {
        $this->stepStarted = false;

        return $this;
    }

    public function markTextStarted(): self
    {
        $this->textStarted = true;

        return $this;
    }

    public function markTextCompleted(): self
    {
        $this->textStarted = false;

        return $this;
    }

    public function markThinkingStarted(): self
    {
        $this->thinkingStarted = true;

        return $this;
    }

    public function markThinkingCompleted(): self
    {
        $this->thinkingStarted = false;

        return $this;
    }

    public function appendText(string $text): self
    {
        $this->currentText .= $text;

        return $this;
    }

    public function appendThinking(string $thinking): self
    {
        $this->currentThinking .= $thinking;

        return $this;
    }

    public function addUsage(Usage $usage): self
    {
        if (! $this->usage instanceof Usage) {
            $this->usage = $usage;

            return $this;
        }

        $this->usage = new Usage(
            promptTokens: $this->usage->promptTokens + $usage->promptTokens,
            completionTokens: $this->usage->completionTokens + $usage->completionTokens,
            cacheWriteInputTokens: ($this->usage->cacheWriteInputTokens ?? 0) + ($usage->cacheWriteInputTokens ?? 0),
            cacheReadInputTokens: ($this->usage->cacheReadInputTokens ?? 0) + ($usage->cacheReadInputTokens ?? 0),
            thoughtTokens: ($this->usage->thoughtTokens ?? 0) + ($usage->thoughtTokens ?? 0)
        );

        return $this;
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function reasoningId(): string
    {
        return $this->reasoningId;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function hasStreamStarted(): bool
    {
        return $this->streamStarted;
    }

    public function hasTextStarted(): bool
    {
        return $this->textStarted;
    }

    public function hasThinkingStarted(): bool
    {
        return $this->thinkingStarted;
    }

    public function shouldEmitStreamStart(): bool
    {
        return ! $this->streamStarted;
    }

    public function shouldEmitStepStart(): bool
    {
        return ! $this->stepStarted;
    }

    public function shouldEmitTextStart(): bool
    {
        return ! $this->textStarted;
    }

    public function shouldEmitThinkingStart(): bool
    {
        return ! $this->thinkingStarted;
    }

    public function currentText(): string
    {
        return $this->currentText;
    }

    public function currentThinking(): string
    {
        return $this->currentThinking;
    }

    public function usage(): ?Usage
    {
        return $this->usage;
    }

    /**
     * Reset state between tool-call turns.
     *
     * Note: streamStarted and usage are intentionally preserved.
     */
    public function reset(): self
    {
        $this->messageId = '';
        $this->reasoningId = '';
        $this->stepStarted = false;
        $this->textStarted = false;
        $this->thinkingStarted = false;
        $this->currentText = '';
        $this->currentThinking = '';

        return $this;
    }
}
