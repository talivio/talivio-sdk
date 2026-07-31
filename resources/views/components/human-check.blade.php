@props([
    /*
     * Livewire/Volt modu. Livewire formu klasik POST etmez — sunucuya bileşenin
     * kendi property'leri gider, DOM'daki gizli alanın değeri DEĞİL. Bu modda
     * alanlar `wire:model` ile bağlanır ve toplayıcı değeri etkileşim boyunca
     * canlı tutar. Bileşen tarafında iki property + kuralın honeypot argümanı
     * gerekir (README "Livewire").
     */
    'livewire' => false,
    'model' => 'talivio_human',
    'honeypotModel' => 'tl_website',
])
{{--
    Davranışsal insan doğrulaması — form İÇİNE yerleştirilir:

        <form method="POST" action="{{ route('register') }}">
            ...
            <x-talivio::human-check />
        </form>

    Üçüncü parti servis yok. Toplayıcı yalnızca toplam sayaç/varyans özetleri
    gönderir; koordinat akışı, tuş içeriği veya cihaz parmak izi toplanmaz.
    Sunucu tarafı karşılığı: Talivio\Sdk\Human\Rules\Human kuralı.

    Kendi formunu JS'te kuran ürünler (Inertia/Vue/React) bu bileşeni değil,
    `resources/js/talivio-human.js` modülünü kullanır — ikisi de AYNI toplayıcı
    çekirdeğini çalıştırır.
--}}
@if (config('talivio.human.enabled'))
    @php($tlToken = app(\Talivio\Sdk\Human\HumanCheck::class)->issue())
    <div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
        {{-- Tuzak alan: insanlar görmez, form dolduran botlar doldurur. --}}
        <label>{{ __('talivio::human.honeypot_label') }}
            <input type="text" name="tl_website" value="" tabindex="-1" autocomplete="off"
                   @if ($livewire) wire:model="{{ $honeypotModel }}" @endif>
        </label>
    </div>
    <input type="hidden" name="talivio_human" value="" @if ($livewire) wire:model="{{ $model }}" @endif>
    {{--
        CSP nonce: nonce'lu content-security-policy kullanan ürünlerde
        (Vite::useCspNonce() — ör. vatlio/FinAI/canopyproof) satır içi script
        nonce'suz SESSİZCE engellenir ve payload hiç dolmaz — her gerçek
        kullanıcı "insan değilsiniz" hatası alırdı. Nonce yoksa boş kalır.
    --}}
    @php($tlNonce = \Illuminate\Support\Facades\Vite::cspNonce())
    <script @if ($tlNonce) nonce="{{ $tlNonce }}" @endif>
    {!! \Talivio\Sdk\Human\HumanCheck::collectorScript() !!}
    (function () {
        'use strict';
        var script = document.currentScript;
        var form = script && script.closest ? script.closest('form') : null;
        if (!form) return;
        var field = form.querySelector('input[name="talivio_human"]');
        if (!field) return;

        window.TalivioHumanCheck.create({
            token: @js($tlToken),
            form: form,
            field: field
        });
    })();
    </script>
@endif
