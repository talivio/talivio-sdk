<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Degradation;

use Talivio\Sdk\Ai\Contracts\DegradationStrategy;

/**
 * VARSAYILAN bozulma davranışı: özellik sessizce kapanır.
 *
 * En muhafazakâr seçenek bilinçli olarak varsayılan: bir şablon yedeği
 * göstermek ürüne göre YANLIŞ olabilir (RiVo'nun sürüş uyarısında doğru,
 * bir destek asistanında yanıltıcı). Ürün ne yapacağını bilmeden en az zarar
 * veren davranış, hiçbir şey söylememektir.
 */
final class DisableFeature implements DegradationStrategy
{
    public function fallback(string $capability, array $request): ?array
    {
        return null;
    }
}
