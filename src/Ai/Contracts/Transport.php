<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Contracts;

/**
 * Ağ geçidine giden taşıma katmanı.
 *
 * Arayüz olmasının sebebi test: istemci projelerin test paketi ASLA ağ geçidine
 * çıkmamalı (fit_web'in `GEMINI_DRIVER=stub` deseni). Çıkarsa testler ağa,
 * gecikmeye ve BÜTÇEYE bağımlı hâle gelir.
 */
interface Transport
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function post(string $path, array $payload): array;
}
