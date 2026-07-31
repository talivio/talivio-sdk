/*!
 * Talivio human-check — davranışsal insan doğrulaması toplayıcısı.
 *
 * ⚠️ TEK KAYNAK. Bu dosya İKİ yoldan birden kullanılır:
 *   1. Blade bileşeni (`<x-talivio::human-check />`) içeriğini satır içine
 *      basar — `HumanCheck::collectorScript()`.
 *   2. Paket kullanıcıları ESM olarak alır — `talivio-human.js` sarmalayıcısı
 *      (Inertia/Vue/React gibi kendi formunu JS'te kuran ürünler).
 *
 * Bu yüzden burada `import`/`export` KULLANILMAZ ve modern sözdiziminden
 * kaçınılır: aynı metin hem klasik `<script>` etiketinde hem de bir bundler
 * içinde çalışmak zorunda. İki ayrı kopya tutmanın bedeli, sürüm başına
 * sessizce sapan iki davranış olurdu (CSP nonce hatası tam olarak böyle bir
 * sapmadan doğdu).
 *
 * Toplanan şey yalnızca TOPLAM SAYAÇ ve VARYANS özetleridir — koordinat
 * akışı, tuş içeriği veya cihaz parmak izi hiçbir zaman gönderilmez.
 */
(function (root) {
    'use strict';

    function createHumanCheck(options) {
        options = options || {};

        var doc = root.document;
        var form = options.form || null;
        var field = options.field || null;
        var endpoint = options.endpoint || '/talivio/human/token';
        var token = options.token || '';

        var t0 = Date.now();
        var mm = 0, md = 0, ma = 0, ts = 0, tc = 0, ky = 0, fo = 0, cl = 0, sc = 0;
        var pt = 'none';
        var lastX = null, lastY = null, lastVX = 0, lastVY = 0;
        var lastKeyT = 0;
        // Welford — tuş aralıkları ve ivmeölçer büyüklüğü için akan varyans.
        var kN = 0, kMean = 0, kM2 = 0;
        var dN = 0, dMean = 0, dM2 = 0;

        var bound = [];
        var syncTimer = null;

        function on(target, type, handler, opts) {
            target.addEventListener(type, handler, opts);
            bound.push([target, type, handler, opts]);
        }

        function variance(n, m2) { return n > 1 ? m2 / (n - 1) : 0; }

        // Tuş ve odak sinyalleri forma kapsanır (verilmişse): sayfanın başka
        // bir yerindeki arama kutusuna yazmak kayıt formunu doldurmuş saymaz.
        function inScope(target) {
            if (!form) return true;
            return !!(target && target.closest && target.closest('form') === form);
        }

        on(doc, 'mousemove', function (e) {
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

        on(doc, 'pointermove', function (e) {
            if (e.pointerType) pt = e.pointerType;
        }, { passive: true });
        on(doc, 'pointerdown', function (e) {
            if (e.pointerType) pt = e.pointerType;
        }, { passive: true });

        on(doc, 'touchstart', function () { ts++; if (pt === 'none') pt = 'touch'; scheduleSync(); }, { passive: true });
        on(doc, 'touchmove', function () { tc++; }, { passive: true });
        on(doc, 'click', function () { cl++; scheduleSync(); }, { passive: true });
        on(doc, 'wheel', function () { sc++; }, { passive: true });
        on(root, 'scroll', function () { sc++; }, { passive: true });

        on(doc, 'keydown', function (e) {
            if (!inScope(e.target)) return;
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
            scheduleSync();
        }, { passive: true });

        on(doc, 'focusin', function (e) { if (inScope(e.target)) fo++; }, { passive: true });

        // iOS 13+ ivmeölçer için kullanıcı izni ister — orada hiç dinlemeyiz;
        // sinyalin yokluğu puan kaybettirmez, varlığı bonus verir.
        if (root.DeviceMotionEvent && typeof root.DeviceMotionEvent.requestPermission !== 'function') {
            on(root, 'devicemotion', function (e) {
                var a = e.accelerationIncludingGravity;
                if (!a || a.x === null) return;
                var mag = Math.sqrt(a.x * a.x + a.y * a.y + a.z * a.z);
                dN++;
                var d1 = mag - dMean;
                dMean += d1 / dN;
                dM2 += d1 * (mag - dMean);
            }, { passive: true });
        }

        function signals() {
            return {
                v: 1,
                tok: token,
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
                wd: root.navigator && root.navigator.webdriver ? 1 : 0
            };
        }

        function payload() {
            return token ? JSON.stringify(signals()) : '';
        }

        /**
         * Livewire modu: gizli alanı güncelleyip `input` olayı yayınlar.
         *
         * NEDEN GEREKLİ: Livewire formu klasik POST etmez — sunucuya bileşenin
         * kendi property'leri gider, DOM'daki gizli alanın değeri DEĞİL. Bu
         * yüzden değeri `wire:model`'in duyacağı şekilde bildirmek gerekir.
         * Yalnızca submit anında yazmak da yetmez: dinleyici sırası Livewire'ın
         * kendi submit işleyicisine göre garanti değildir, bu yüzden değer
         * etkileşim boyunca (kısılmış olarak) canlı tutulur.
         */
        function sync() {
            if (!field) return;
            var value = payload();
            if (!value || field.value === value) return;
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function scheduleSync() {
            if (!field || syncTimer) return;
            syncTimer = root.setTimeout(function () {
                syncTimer = null;
                sync();
            }, 500);
        }

        if (form) {
            on(form, 'submit', function () {
                if (field) { field.value = payload(); }
                sync();
            });
        }

        // Jeton satır içi verilmediyse (SPA) sunucudan alınır — oturuma bağlı
        // olduğu için çerezle aynı origin'e gitmesi şart.
        var ready = token
            ? Promise.resolve(token)
            : root.fetch(endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : { token: '' }; })
                .then(function (d) { token = (d && d.token) || ''; sync(); return token; })
                .catch(function () { return ''; });

        function destroy() {
            if (syncTimer) { root.clearTimeout(syncTimer); syncTimer = null; }
            for (var i = 0; i < bound.length; i++) {
                bound[i][0].removeEventListener(bound[i][1], bound[i][2], bound[i][3]);
            }
            bound = [];
        }

        return {
            payload: payload,
            signals: signals,
            token: function () { return token; },
            ready: ready,
            destroy: destroy
        };
    }

    root.TalivioHumanCheck = { create: createHumanCheck };
})(typeof globalThis !== 'undefined' ? globalThis : window);
