<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Transports;

use Talivio\Sdk\Ai\Contracts\Transport;

/**
 * Test sürücüsü. İstemci projelerin test paketinde varsayılan olmalı.
 *
 * Sıraya konmuş yanıtlar tükendiğinde SON yanıt tekrarlanır: testlerin çoğu
 * "her çağrıda aynı şeyi dön" ister ve her biri için ayrı satır yazmak gürültü
 * olurdu.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{status: int, body: array<string, mixed>}> */
    private array $queue = [];

    /** @var list<array{path: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function reply(string $text = 'test yanıtı', array $usage = [], int $status = 200): self
    {
        $this->queue[] = ['status' => $status, 'body' => [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'talivio' => $usage],
        ]];

        return $this;
    }

    public function fail(int $status = 503, string $code = 'provider_unavailable'): self
    {
        $this->queue[] = ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => 'test hatası']]];

        return $this;
    }

    public function raw(int $status, array $body): self
    {
        $this->queue[] = ['status' => $status, 'body' => $body];

        return $this;
    }

    public function post(string $path, array $payload): array
    {
        $this->sent[] = ['path' => $path, 'payload' => $payload];

        if ($this->queue === []) {
            return ['status' => 200, 'body' => [
                'choices' => [['message' => ['content' => 'test yanıtı']]],
                'usage' => [],
            ]];
        }

        return count($this->queue) === 1 ? $this->queue[0] : array_shift($this->queue);
    }
}
