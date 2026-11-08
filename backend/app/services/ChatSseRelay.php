<?php
declare(strict_types=1);

final class ChatSseRelay
{
    private const MAX_BUFFER_BYTES = 262144;
    private const FAILURE_MESSAGE = 'Trợ lý đang bận, bạn thử lại sau nhé.';

    private string $buffer = '';
    private bool $pendingCarriageReturn = false;
    private $out;
    private $applyEffect;
    private bool $terminal = false;

    public function __construct(
        callable $out,
        callable $applyEffect
    ) {
        $this->out = $out;
        $this->applyEffect = $applyEffect;
    }

    public function feed(string $chunk): int
    {
        if ($this->terminal) {
            return 0;
        }

        $this->appendNormalisedChunk($chunk);
        if (strlen($this->buffer) > self::MAX_BUFFER_BYTES) {
            $this->buffer = '';
            $this->fail();
            return 0;
        }

        while (true) {
            $position = strpos($this->buffer, "\n\n");
            if ($position === false) {
                return strlen($chunk);
            }

            $raw = substr($this->buffer, 0, $position + 2);
            $this->buffer = substr($this->buffer, $position + 2);
            $this->dispatch($raw);
            if ($this->terminal) {
                return 0;
            }
        }
    }

    public function finish(): void
    {
        if ($this->terminal) {
            return;
        }

        if ($this->pendingCarriageReturn) {
            $this->buffer .= "\n";
            $this->pendingCarriageReturn = false;
        }

        while (($position = strpos($this->buffer, "\n\n")) !== false) {
            $raw = substr($this->buffer, 0, $position + 2);
            $this->buffer = substr($this->buffer, $position + 2);
            $this->dispatch($raw);
            if ($this->terminal) {
                return;
            }
        }

        $this->buffer = '';
        $this->fail();
    }

    public function fail(): void
    {
        if ($this->terminal) {
            return;
        }

        $this->terminal = true;
        ($this->out)("event: error\ndata: " . json_encode(
            ['message' => self::FAILURE_MESSAGE],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n\n");
        ($this->out)("event: done\ndata: {}\n\n");
    }

    public function hasTerminal(): bool
    {
        return $this->terminal;
    }

    private function appendNormalisedChunk(string $chunk): void
    {
        if ($this->pendingCarriageReturn) {
            $this->buffer .= "\n";
            $this->pendingCarriageReturn = false;
            if ($chunk !== '' && $chunk[0] === "\n") {
                $chunk = substr($chunk, 1);
            }
        }

        if ($chunk === '') {
            return;
        }

        if (str_ends_with($chunk, "\r")) {
            $chunk = substr($chunk, 0, -1);
            $this->pendingCarriageReturn = true;
        }
        $this->buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);
    }

    private function dispatch(string $raw): void
    {
        $event = '';
        $events = [];
        $data = [];
        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
                $events[] = $event;
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $value = substr($line, 5);
                $data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
            }
        }

        if (in_array('cart_effect', $events, true)) {
            if (count($events) !== 1 || $event !== 'cart_effect') {
                return;
            }

            $effect = json_decode(implode("\n", $data), true);
            if (!is_array($effect)) {
                return;
            }
            // Flask wraps the signed effect as {"effect": {...}}; hand the inner array on unchanged.
            if (array_keys($effect) === ['effect'] && is_array($effect['effect'])) {
                $effect = $effect['effect'];
            }

            try {
                $count = ($this->applyEffect)($effect);
            } catch (Throwable $e) {
                return;
            }
            if (!is_int($count)) {
                return;
            }

            ($this->out)("event: cart_updated\ndata: " . json_encode(
                ['count' => $count],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . "\n\n");
            return;
        }

        ($this->out)(rtrim($raw, "\n") . "\n\n");
        if ($event === 'done' || $event === 'error') {
            $this->terminal = true;
        }
    }
}
