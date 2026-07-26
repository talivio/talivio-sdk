<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai;

/**
 * İstemci tarafındaki sonuç nesnesi.
 *
 * ⚠️ HATA DURUMUNDA İSTİSNA FIRLATILMAZ (ADR-17). Ağ geçidi tek kapı olduğu ve
 * doğrudan sağlayıcı yolu bulunmadığı için, geçidin arızası istemci uygulamanın
 * çökmesi anlamına GELMEMELİ. Çağıran taraf `$result->ok` bakar ve AI'sız yola
 * devam eder.
 *
 * `degraded` alanı "yanıt geldi ama AI'dan değil" demektir — şablon yedeği ya da
 * kapatılmış özellik. Bunu `ok = false` ile karıştırmamak önemli: özellik
 * bilinçli kapatıldığında akış devam eder, hata durumunda etmez.
 */
final readonly class AiResult
{
    /**
     * @param  list<array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public bool $ok,
        public string $text = '',
        public array $toolCalls = [],
        public array $usage = [],
        public bool $degraded = false,
        public ?string $reason = null,
    ) {}

    public static function success(string $text, array $toolCalls = [], array $usage = []): self
    {
        return new self(true, $text, $toolCalls, $usage);
    }

    /** Ağ geçidi erişilemez ve bozulma davranışı bir yedek metin verdi. */
    public static function fallback(string $text, string $reason): self
    {
        return new self(true, $text, degraded: true, reason: $reason);
    }

    /** Özellik sessizce kapatıldı: çağıran taraf AI'sız devam eder. */
    public static function unavailable(string $reason): self
    {
        return new self(false, degraded: true, reason: $reason);
    }

    /** Yapılandırılmış çıktıyı diziye çevirir; geçersiz JSON'da null. */
    public function json(): ?array
    {
        $decoded = json_decode(trim($this->text), true);

        return is_array($decoded) ? $decoded : null;
    }
}
