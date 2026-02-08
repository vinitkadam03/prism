<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Concerns;

use Illuminate\Support\Str;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Psr\Http\Message\StreamInterface;
use Throwable;

trait ParsesSSE
{
    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws PrismStreamDecodeException
     */
    protected function parseSSEDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with((string) $line, 'data:')) {
            return null;
        }

        $line = trim(substr((string) $line, strlen('data:')));

        if ($line === '' || $line === '[DONE]' || Str::contains($line, '[DONE]')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException($this->providerName(), $e);
        }
    }

    abstract public function providerName(): string;
}
