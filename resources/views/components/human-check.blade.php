{{--
    Davranışsal insan doğrulaması — form İÇİNE yerleştirilir:

        <form method="POST" action="{{ route('register') }}">
            ...
            <x-talivio::human-check />
        </form>

    Üçüncü parti servis yok. Toplayıcı yalnızca toplam sayaç/varyans özetleri
    gönderir; koordinat akışı, tuş içeriği veya cihaz parmak izi toplanmaz.
    Sunucu tarafı karşılığı: Talivio\Sdk\Human\Rules\Human kuralı.
--}}
@if (config('talivio.human.enabled'))
    @php($tlToken = app(\Talivio\Sdk\Human\HumanCheck::class)->issue())
    <div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
        {{-- Tuzak alan: insanlar görmez, form dolduran botlar doldurur. --}}
        <label>{{ __('talivio::human.honeypot_label') }}
            <input type="text" name="tl_website" value="" tabindex="-1" autocomplete="off">
        </label>
    </div>
    <input type="hidden" name="talivio_human" value="">
    {{--
        CSP nonce: nonce'lu content-security-policy kullanan ürünlerde
        (Vite::useCspNonce() — ör. vatlio/FinAI/canopyproof) satır içi script
        nonce'suz SESSİZCE engellenir ve payload hiç dolmaz — her gerçek
        kullanıcı "insan değilsiniz" hatası alırdı. Nonce yoksa boş kalır.
    --}}
    @php($tlNonce = \Illuminate\Support\Facades\Vite::cspNonce())
    <script @if ($tlNonce) nonce="{{ $tlNonce }}" @endif>
    (function () {
        'use strict';
        var script = document.currentScript;
        var form = script && script.closest ? script.closest('form') : null;
        if (!form) return;
        var field = form.querySelector('input[name="talivio_human"]');
        if (!field) return;

        var t0 = Date.now();
        var mm = 0, md = 0, ma = 0, ts = 0, tc = 0, ky = 0, fo = 0, cl = 0, sc = 0;
        var pt = 'none';
        var lastX = null, lastY = null, lastVX = 0, lastVY = 0;
        var lastKeyT = 0;
        // Welford — tuş aralıkları ve ivmeölçer büyüklüğü için akan varyans.
        var kN = 0, kMean = 0, kM2 = 0;
        var dN = 0, dMean = 0, dM2 = 0;

        function variance(n, m2) { return n > 1 ? m2 / (n - 1) : 0; }

        document.addEventListener('mousemove', function (e) {
            mm++;
            if (lastX !== null) {
                var dx = e.clientX - lastX, dy = e.clientY - lastY;
                var dist = Math.sqrt(dx * dx + dy * dy);
                md += dist;
                if (dist > 1 && (lastVX || lastVY)) {
                    // Yön değişimi: ardışık hareket vektörleri arasındaki açı
                    // ~30°'yi aşarsa say — insan eli düz çizgi çizmez.
                    var dot = dx * lastVX + dy * lastVY;
                    var mag = dist * Math.sqrt(lastVX * lastVX + lastVY * lastVY);
                    if (mag > 0 && dot / mag < 0.86) ma++;
                }
                if (dist > 1) { lastVX = dx; lastVY = dy; }
            }
            lastX = e.clientX; lastY = e.clientY;
        }, { passive: true });

        document.addEventListener('pointermove', function (e) {
            if (e.pointerType) pt = e.pointerType;
        }, { passive: true });
        document.addEventListener('pointerdown', function (e) {
            if (e.pointerType) pt = e.pointerType;
        }, { passive: true });

        document.addEventListener('touchstart', function () { ts++; if (pt === 'none') pt = 'touch'; }, { passive: true });
        document.addEventListener('touchmove', function () { tc++; }, { passive: true });
        document.addEventListener('click', function () { cl++; }, { passive: true });
        document.addEventListener('wheel', function () { sc++; }, { passive: true });
        window.addEventListener('scroll', function () { sc++; }, { passive: true });

        document.addEventListener('keydown', function (e) {
            var target = e.target;
            if (!target || !target.closest || target.closest('form') !== form) return;
            ky++;
            var now = Date.now();
            if (lastKeyT && now - lastKeyT < 5000) {
                var delta = now - lastKeyT;
                kN++;
                var d1 = delta - kMean;
                kMean += d1 / kN;
                kM2 += d1 * (delta - kMean);
            }
            lastKeyT = now;
        }, { passive: true });

        form.addEventListener('focusin', function () { fo++; });

        // iOS 13+ ivmeölçer için kullanıcı izni ister — orada hiç dinlemeyiz;
        // sinyalin yokluğu puan kaybettirmez, varlığı bonus verir.
        if (window.DeviceMotionEvent && typeof DeviceMotionEvent.requestPermission !== 'function') {
            window.addEventListener('devicemotion', function (e) {
                var a = e.accelerationIncludingGravity;
                if (!a || a.x === null) return;
                var mag = Math.sqrt(a.x * a.x + a.y * a.y + a.z * a.z);
                dN++;
                var d1 = mag - dMean;
                dMean += d1 / dN;
                dM2 += d1 * (mag - dMean);
            }, { passive: true });
        }

        form.addEventListener('submit', function () {
            field.value = JSON.stringify({
                v: 1,
                tok: @js($tlToken),
                st: Date.now() - t0,
                pt: pt,
                mm: mm,
                md: Math.round(md),
                ma: ma,
                ts: ts,
                tc: tc,
                dm: Math.round(variance(dN, dM2) * 1000),
                ky: ky,
                kv: Math.round(variance(kN, kM2)),
                fo: fo,
                cl: cl,
                sc: sc,
                wd: navigator.webdriver ? 1 : 0
            });
        });
    })();
    </script>
@endif
