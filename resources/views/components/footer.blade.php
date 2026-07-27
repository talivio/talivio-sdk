{{--
    Talivio standart alt bilgisi — TEK KAYNAK (`<x-talivio::footer />`).

    Sözleşme: talivio-ai/docs/tasarim-dili.md §5. Biçim ai.talivio.com,
    vendio.talivio.com ve rivo.talivio.com ile aynı: koyu şerit → 4 sütun
    (marka · ÜRÜN · YASAL · İLETİŞİM) → alt şerit (telif + "Talivio yapımı").

    Kullanım:

        <x-talivio::footer product="Talivio AI" :tagline="__('ui.tagline')">
            <x-slot:links>
                <li><a href="…" class="transition hover:text-brand-300">Sohbet</a></li>
            </x-slot:links>
        </x-talivio::footer>

    ÜRÜNE GÖRE DEĞİŞEN (prop/slot):
      · product   — marka satırındaki ad (varsayılan: `config('app.name')`)
      · tagline   — bir cümlelik tanım; verilmezse paragraf hiç basılmaz
      · links     — ÜRÜN sütunundaki <li> bağlantıları; verilmezse SÜTUN HİÇ
                    BASILMAZ (boş başlık yerine kısa alt bilgi)
      · switches  — dil/tema seçici gibi ürüne ait bileşenler (SDK bunları
                    bilemez: her ürünün kendi seçici bileşeni var)
      · *-token   — denetim rozetleri, aşağıya bakın

    DEĞİŞMEYEN (bileşenin içinde sabit): YASAL sütunu, iletişim bilgileri,
    adres, sosyal hesaplar, telif satırı. Bunlar 20 üründe de aynı — prop
    yapılsaydı ilk yanlış yazılan üründe kurumsal bilgi sapardı.

    ⚠️ DENETİM ROZETLERİ YALNIZCA JETON VERİLİRSE BASILIR. Her rozet o ürüne
    ait bir jeton taşır (`…/status/<jeton>`, `…/badge/<jeton>.svg`); jetonu
    olmayan üründe rozet HİÇ görünmez. Varsayılan bir jeton koymak ya da
    rozeti jetonsuz basmak, ÇALIŞMAYAN bir güvenlik iddiası göstermek olurdu.

    ⚠️ BÜLTEN KUTUSU BİLEREK YOK. Kardeş üründe var, ama orada arkasında
    gerçek bir liste ve uç nokta var. Buraya koysaydık, abone olan kimseye
    ulaşamayacağımız bir form 20 üründe birden yayına girerdi.

    ⚠️ "Dokümantasyon", "Yardım merkezi" gibi bağlantılar bileşende YOK:
    o sayfa gerçekten varsa ürün kendi `links` slotuna koyar.

    ⚠️ Sınıfları (`cut-sm`, `text-brand-300`, …) CSS'e girsin diye ürünün
    `app.css` dosyasında SDK view dizinini tarayan `@source` satırı olmak
    ZORUNDA — README "Tasarım dili". Taranmadığında bu alt bilgi yarı stilsiz
    çıkar.

    ⚠️ Alt bilginin son pikselleri kaydırılamıyorsa sebep burası değil: gövdeye
    `h-full` (height:100%) yazan kabuk. Belge sayfaları `min-h-full` olmalı.
--}}
@props([
    'product' => null,
    'tagline' => null,
    'securityToken' => null,
    'a11yproofToken' => null,
    'privascanToken' => null,
])

@php
    $brand = trim((string) ($product ?? config('app.name', 'Talivio')));

    // "Talivio AI" → "Talivio" beyaz, "AI" marka mavisi. Adı "Talivio" ile
    // başlamayan ürünlerde (vendio, rivo) tek parça basılır.
    $brandSuffix = str_starts_with($brand, 'Talivio ') ? substr($brand, 8) : null;

    $productLinks = $links ?? null;
    $switchSlot = $switches ?? null;

    // Rozetler: jeton → [servis, jeton, başlık anahtarı, alt metin anahtarı].
    // Jetonu olmayan servis diziye HİÇ girmez (yukarıdaki uyarı).
    $badges = array_values(array_filter([
        $securityToken ? ['security', $securityToken, 'badge_security_title', 'badge_security_alt'] : null,
        $a11yproofToken ? ['a11yproof', $a11yproofToken, 'badge_a11y_title', 'badge_a11y_alt'] : null,
        $privascanToken ? ['privascan', $privascanToken, 'badge_privacy_title', 'badge_privacy_alt'] : null,
    ]));
@endphp

<footer {{ $attributes->merge(['class' => 'mt-20 border-t border-white/5 bg-neutral-950 text-neutral-400']) }}>
    <div class="mx-auto max-w-6xl px-5 pt-14 pb-8">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-4">

            {{-- Marka --}}
            <div>
                <div class="flex items-center gap-2.5">
                    {{-- ⚠️ `on-dark`: şerit HER TEMADA koyu; tema kuralına uyan
                         logo açık temada siyah üstüne siyah çıkıyordu. --}}
                    <x-talivio::logo class="h-8 w-8" :on-dark="true" />
                    <span class="text-lg font-bold text-white">
                        @if ($brandSuffix !== null)
                            Talivio <span class="font-medium text-brand-300">{{ $brandSuffix }}</span>
                        @else
                            {{ $brand }}
                        @endif
                    </span>
                </div>

                @if (filled($tagline))
                    <p class="mt-4 max-w-xs text-sm leading-relaxed">{{ $tagline }}</p>
                @endif

                <div class="mt-5 flex items-center gap-2" aria-label="{{ __('talivio::footer.follow') }}">
                    @foreach ([
                        ['https://www.linkedin.com/company/taliviotechnology/', 'LinkedIn', 'M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-1 1.83-2.05 3.77-2.05C20.5 8.65 21 11 21 14.2V21h-4v-6c0-1.43-.03-3.27-2-3.27-2 0-2.3 1.56-2.3 3.17V21H9z'],
                        ['https://www.x.com/taliviotech', 'X', 'M17.5 3h3.2l-7 8 8.2 10h-6.4l-5-6.1L4.7 21H1.5l7.5-8.6L1.2 3h6.6l4.5 5.6zm-1.1 16h1.8L7.7 4.8H5.8z'],
                        ['https://www.instagram.com/taliviotechnology', 'Instagram', 'M12 2.2c3.2 0 3.6 0 4.9.07 3.3.15 4.8 1.7 4.9 4.9.06 1.3.07 1.7.07 4.8s0 3.6-.07 4.9c-.15 3.2-1.7 4.8-4.9 4.9-1.3.06-1.7.07-4.9.07s-3.6 0-4.9-.07c-3.3-.15-4.8-1.7-4.9-4.9C2.2 15.6 2.2 15.2 2.2 12s0-3.6.07-4.9c.15-3.2 1.7-4.8 4.9-4.9C8.4 2.2 8.8 2.2 12 2.2zm0 3.2a6.6 6.6 0 1 0 0 13.2 6.6 6.6 0 0 0 0-13.2zm0 10.9a4.3 4.3 0 1 1 0-8.6 4.3 4.3 0 0 1 0 8.6zm6.8-11.1a1.55 1.55 0 1 1-3.1 0 1.55 1.55 0 0 1 3.1 0z'],
                    ] as [$href, $label, $path])
                        <a href="{{ $href }}" aria-label="{{ $label }}" target="_blank" rel="noopener"
                           class="cut-sm bg-white/5 p-2 text-neutral-400 transition hover:bg-white/10 hover:text-white">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="{{ $path }}"/></svg>
                        </a>
                    @endforeach
                </div>

                @if ($badges !== [])
                    {{-- Rozet görselleri denetim servisinden CANLI gelir: not
                         düşerse alt bilgi de düşer, elle güncellenmez. --}}
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        @foreach ($badges as [$service, $token, $titleKey, $altKey])
                            <a href="https://{{ $service }}.talivio.com/status/{{ $token }}"
                               target="_blank" rel="noopener" class="group inline-flex items-center"
                               title="{{ __('talivio::footer.'.$titleKey) }}">
                                <img src="https://{{ $service }}.talivio.com/badge/{{ $token }}.svg"
                                     alt="{{ __('talivio::footer.'.$altKey, ['product' => $brand]) }}"
                                     width="140" height="36" loading="lazy"
                                     class="h-9 w-auto opacity-90 transition-opacity group-hover:opacity-100">
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($switchSlot !== null && ! $switchSlot->isEmpty())
                    {{-- Dil ve tema seçici: başlıkta da olabilir, ama alt bilgi
                         her Talivio üründe bunların arandığı yer. --}}
                    <div class="mt-5 -ml-3 flex flex-wrap items-center gap-1">{{ $switchSlot }}</div>
                @endif
            </div>

            {{-- ÜRÜN — bağlantı verilmediyse sütun hiç basılmaz --}}
            @if ($productLinks !== null && ! $productLinks->isEmpty())
                <div>
                    <p class="text-sm font-bold tracking-wider text-neutral-200 uppercase">{{ __('talivio::footer.product') }}</p>
                    <ul class="mt-4 space-y-3 text-sm">{{ $productLinks }}</ul>
                </div>
            @endif

            {{-- YASAL — her üründe aynı --}}
            <div>
                <p class="text-sm font-bold tracking-wider text-neutral-200 uppercase">{{ __('talivio::footer.legal') }}</p>
                <ul class="mt-4 space-y-3 text-sm">
                    <li><a href="https://talivio.com/privacy" class="transition hover:text-brand-300">{{ __('talivio::footer.privacy') }}</a></li>
                    <li><a href="https://talivio.com/terms" class="transition hover:text-brand-300">{{ __('talivio::footer.terms') }}</a></li>
                    <li><a href="https://talivio.com/impressum" class="transition hover:text-brand-300">{{ __('talivio::footer.impressum') }}</a></li>
                </ul>
            </div>

            {{-- İLETİŞİM — her üründe aynı; adres üç dilde de birebir aynı
                 yazıldığı için çeviri anahtarı değil, sabit. --}}
            <div>
                <p class="text-sm font-bold tracking-wider text-neutral-200 uppercase">{{ __('talivio::footer.contact') }}</p>
                <ul class="mt-4 space-y-3 text-sm">
                    <li><a href="tel:+37282721454" class="transition hover:text-brand-300">+372 8272 1454</a></li>
                    <li><a href="mailto:info@talivio.com" class="transition hover:text-brand-300">info@talivio.com</a></li>
                    <li class="leading-relaxed text-neutral-500">Harju, Tallinn, Ahtri tn 12, 15551</li>
                </ul>
            </div>
        </div>

        <div class="mt-14 flex flex-col items-center justify-between gap-3 border-t border-white/10 pt-8 sm:flex-row">
            <p class="text-sm text-neutral-500">{{ __('talivio::footer.rights_company', ['year' => date('Y')]) }}</p>

            <p class="flex items-center gap-2 text-xs text-neutral-500">
                {{ __('talivio::footer.made_by') }}
                <a href="https://talivio.com" class="inline-flex items-center gap-1.5 font-semibold text-neutral-300 transition hover:text-white">
                    <x-talivio::logo class="h-4 w-4" :on-dark="true" />
                    Talivio
                </a>
            </p>
        </div>
    </div>
</footer>
