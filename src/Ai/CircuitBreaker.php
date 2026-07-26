<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * ADR-17 — KISA DEVRE.
 *
 * Arızalı bir ağ geçidine her istekte yeniden gitmek, gecikmeyi kullanıcıya
 * ödetir: 5 saniyelik zaman aşımı × her istek. Devre açıkken hiç denenmez,
 * doğrudan bozulma davranışı uygulanır.
 *
 * Sayaç ÖNBELLEKTE tutulur, bellekte değil: php-fpm'de her istek yeni bir süreç
 * olduğu için bellekteki sayaç hiçbir zaman eşiğe ulaşamazdı.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $threshold,
        private readonly int $cooldownSeconds,
    ) {}

    public function isOpen(): bool
    {
        return $this->threshold > 0 && $this->cache->get($this->key('open'), false) === true;
    }

    public function recordFailure(): void
    {
        if ($this->threshold <= 0) {
            return;
        }

        // Sayaç penceresi soğuma süresinin iki katı: tek tek dağılmış hataların
        // devreyi açması istenmez, arıza YOĞUNLAŞMASI istenir.
        $failures = (int) $this->cache->get($this->key('failures'), 0) + 1;
        $this->cache->put($this->key('failures'), $failures, $this->cooldownSeconds * 2);

        if ($failures >= $this->threshold) {
            $this->cache->put($this->key('open'), true, $this->cooldownSeconds);
            $this->cache->forget($this->key('failures'));
        }
    }

    public function recordSuccess(): void
    {
        $this->cache->forget($this->key('failures'));
        $this->cache->forget($this->key('open'));
    }

    private function key(string $suffix): string
    {
        return "talivio-ai:breaker:{$suffix}";
    }
}
