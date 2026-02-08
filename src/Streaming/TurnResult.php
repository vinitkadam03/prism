<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Usage;

class TurnResult
{
    /**
     * @param  array<string, mixed>  $additionalContent
     * @param  array<string, mixed>  $toolCallAdditionalContent
     */
    public function __construct(
        public readonly ?FinishReason $finishReason = null,
        public readonly ?Usage $usage = null,
        public readonly string $model = '',
        public readonly array $additionalContent = [],
        public readonly array $toolCallAdditionalContent = [],
    ) {}
}
