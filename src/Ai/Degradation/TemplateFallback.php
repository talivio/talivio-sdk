<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Degradation;

use Talivio\Sdk\Ai\Contracts\DegradationStrategy;

/**
 * Şablon yedeği: AI'sız da bir cevap üretilmek zorunda olan yerler için.
 *
 * Somut gerekçe: RiVo'nun sürüş güvenliği uyarıları (`2.MIG.07` ön koşulu).
 * Yorgunluk ya da radar uyarısı, ağ geçidi çökmüş olsa bile sürücüye
 * ULAŞMALIDIR — AI'sız hâli daha genel bir metindir ama sessizlikten iyidir.
 */
final class TemplateFallback implements DegradationStrategy
{
    /** @param array<string, string> $templates yetenek => yedek metin */
    public function __construct(private readonly array $templates) {}

    public function fallback(string $capability, array $request): ?array
    {
        $text = $this->templates[$capability] ?? null;

        return $text === null ? null : ['content' => $text];
    }
}
