/**
 * Talivio human-check — kendi formunu JS'te kuran ürünler için ESM girişi
 * (Inertia/Vue/React). Blade formları için `<x-talivio::human-check />`
 * bileşeni yeterlidir; bu modüle gerek yoktur.
 *
 * Kurulum (ürünün kendi JS'inde, vendor yolundan — CSS'te olduğu gibi):
 *
 *     import { createHumanCheck } from '../../vendor/talivio/sdk/resources/js/talivio-human.js';
 *
 *     const human = createHumanCheck();          // jetonu sunucudan alır
 *     // form gönderilirken:
 *     form.talivio_human = human.payload();
 *
 * Toplayıcı sayfa ömrü boyunca sinyal biriktirir; `payload()` o anki özeti
 * JSON string olarak döndürür (jeton henüz gelmediyse boş string — sunucu
 * tarafı bunu zaten reddeder, sessizce "geçti" saymaz).
 *
 * ⚠️ Uygulama `talivio-human-core.js`'tedir ve Blade bileşeni de AYNI dosyayı
 * satır içine basar; buradaki sarmalayıcı yalnızca ESM arayüzüdür.
 */
import './talivio-human-core.js';

export function createHumanCheck(options = {}) {
    return globalThis.TalivioHumanCheck.create(options);
}

export default createHumanCheck;
