{{--
    `href` ile hedef değiştirilebilir: bazı ürünler SDK'nın hazır callback'ini
    kullanamıyor (ör. talivio-ai, davet + 2FA katmanlarını korumak için kendi
    akışını kuruyor). Butonun görünümü tek yerde kalsın diye ikonu kopyalamak
    yerine hedefi parametreleştiriyoruz.

    ⚠️⚠️ TAILWIND `dark:` VARYANTLARI KALDIRILDI — GERÇEK BİR ÜRETİM HATASININ
    DÜZELTMESİ (2026-08-14, finai.talivio.com'da tarayıcıda ölçüldü):

      Buton eskiden rengini `dark:bg-white/5 dark:text-slate-100 …` ile
      alıyordu. `dark:`in NE ZAMAN tetikleneceği HOST UYGULAMANIN Tailwind
      yapılandırmasına bağlı ve ürünler arasında AYNI DEĞİL:

        • `darkMode: 'media'` kullanan üründe `dark:` = İŞLETİM SİSTEMİ
          tercihi. Sayfa kendi renkleriyle AÇIK tema render edilse bile,
          kullanıcının OS'i koyuysa buton koyu stilini alıyor →
          BEYAZ ÜSTÜNE BEYAZ YAZI. finai'de tam olarak bu oldu (ölçüm:
          metin #f1f5f9, zemin beyaz — okunmuyordu).

        • Filament gibi ÖNCEDEN DERLENMİŞ CSS kullanan panellerde ise bu
          sınıflar CSS'e hiç girmiyor (paket blade'leri taranmıyor) →
          renkler sessizce hiç uygulanmıyor.

      Çözüm: renkler artık paketin KENDİ yaydığı düz CSS'ten geliyor ve koyu
      tema, görsel dil sözleşmesinin kullandığı İKİ SEÇİCİYLE belirleniyor
      (`.dark` sınıfı ve `[data-theme='dark']` özniteliği) — OS tercihiyle
      DEĞİL. Böylece buton, sayfanın GERÇEKTEN hangi temada olduğunu izliyor.

    ⚠️ `<style>` neden burada, bir CSS dosyasında değil: `talivio-primitives.css`
    `@layer components` içinde ve Tailwind derlemesi gerektiriyor;
    `talivio-design.css`i de her ürün yüklemiyor (kimi public/'e kopyalıyor,
    kimi hiç almıyor). Buton HER üründe doğru görünmek zorunda, o yüzden
    kendi kendine yetiyor. `@once` sayesinde sayfada birden çok buton olsa
    da blok bir kez basılır.

    ⚠️ TÜM SEÇİCİLER `:where()` İÇİNDE — ÖZGÜLLÜĞÜ SIFIRLAMAK İÇİN. Bu blok
    `<body>`de basıldığı için kaynak sırasında ürünün `<head>`deki CSS'inden
    SONRA geliyor; `:where()` olmasaydı eşit özgüllükte ürünün kendi
    kuralını EZERDİ. Ürünler bu butonu gerçekten özelleştiriyor (ör. rivo
    `class="cut-sm rivo-sso-btn"` ile kendi rengini veriyor) — paket yalnız
    VARSAYILAN sağlamalı, karar ürünün. `:where()` ile tek bir sınıf bile
    bu değerleri ezer.
--}}
@props(['label' => 'Talivio Accounts ile devam et', 'href' => null])

@once
<style>
    :where(.talivio-accounts-btn) {
        display: inline-flex;
        width: 100%;
        align-items: center;
        justify-content: center;
        gap: .625rem;
        padding: .625rem 1rem;
        font-size: .875rem;
        font-weight: 600;
        line-height: 1.25rem;
        border-radius: .5rem;
        border: 1px solid #cbd5e1;
        background-color: #ffffff;
        color: #334155;
        text-decoration: none;
        box-shadow: 0 1px 2px 0 rgb(0 0 0 / .05);
        transition: background-color .15s, border-color .15s;
    }
    :where(.talivio-accounts-btn):hover { background-color: #f8fafc; }

    :where(.dark .talivio-accounts-btn, [data-theme='dark'] .talivio-accounts-btn) {
        border-color: rgba(255, 255, 255, .15);
        background-color: rgba(255, 255, 255, .05);
        color: #f1f5f9;
    }
    :where(.dark .talivio-accounts-btn, [data-theme='dark'] .talivio-accounts-btn):hover {
        background-color: rgba(255, 255, 255, .1);
    }

    /*
     * İşaret takası (eskiden `dark:hidden` / `hidden dark:block`).
     *
     * ⚠️ BURADA BİLEREK `:where()` YOK — renklerin aksine. `:where()`
     * özgüllüğü SIFIRA indiriyor ve Tailwind preflight'ının
     * `svg { display: block }` ELEMENT seçicisi (0,0,1) onu EZİYOR:
     * koyu işaret gizlenmiyor, açık temada İKİ LOGO YAN YANA çıkıyordu
     * (tarayıcıda görüldü). Bu bir görünüm tercihi değil yapısal bir
     * takas, ürünlerin ezmesi de gerekmiyor — sınıf seçicisi kalıyor.
     */
    .talivio-accounts-btn__mark--dark { display: none; }
    .dark .talivio-accounts-btn__mark--light,
    [data-theme='dark'] .talivio-accounts-btn__mark--light { display: none; }
    .dark .talivio-accounts-btn__mark--dark,
    [data-theme='dark'] .talivio-accounts-btn__mark--dark { display: inline-block; }
</style>
@endonce

<a href="{{ $href ?? route('talivio.login') }}"
   {{ $attributes->merge(['class' => 'talivio-accounts-btn']) }}>
    <svg width="18" height="18" viewBox="0 0 101.67 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="talivio-accounts-btn__mark--light">
        <path fill="#0072CE" opacity=".8" d="M67.5 59.12V33.33H83a23.1 23.1 0 0 1 18.63 18.51v14.83H75a7.55 7.55 0 0 1-7.5-7.55z"/>
        <path fill="#000000" d="M34.16 25.79V0h31.91a4.67 4.67 0 0 1 3.3 1.36l30.94 31a4.66 4.66 0 0 1 1.36 3.29v16.19A23.1 23.1 0 0 0 83 33.33H41.71a7.54 7.54 0 0 1-7.55-7.54z"/>
        <path fill="#0072CE" opacity=".8" d="M34.18 40.88v25.79H18.64A23.12 23.12 0 0 1 0 48.16V33.33h26.63a7.55 7.55 0 0 1 7.55 7.55z"/>
        <path fill="#000000" d="M67.51 74.21V100H35.6a4.66 4.66 0 0 1-3.29-1.36L1.36 67.69A4.66 4.66 0 0 1 0 64.4V48.16a23.12 23.12 0 0 0 18.64 18.51H60a7.54 7.54 0 0 1 7.51 7.54z"/>
    </svg>
    <svg width="18" height="18" viewBox="0 0 101.67 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="talivio-accounts-btn__mark--dark">
        <path fill="#ffffff" opacity=".8" d="M67.5 59.12V33.33H83a23.1 23.1 0 0 1 18.63 18.51v14.83H75a7.55 7.55 0 0 1-7.5-7.55z"/>
        <path fill="#0072CE" d="M34.16 25.79V0h31.91a4.67 4.67 0 0 1 3.3 1.36l30.94 31a4.66 4.66 0 0 1 1.36 3.29v16.19A23.1 23.1 0 0 0 83 33.33H41.71a7.54 7.54 0 0 1-7.55-7.54z"/>
        <path fill="#ffffff" opacity=".8" d="M34.18 40.88v25.79H18.64A23.12 23.12 0 0 1 0 48.16V33.33h26.63a7.55 7.55 0 0 1 7.55 7.55z"/>
        <path fill="#0072CE" d="M67.51 74.21V100H35.6a4.66 4.66 0 0 1-3.29-1.36L1.36 67.69A4.66 4.66 0 0 1 0 64.4V48.16a23.12 23.12 0 0 0 18.64 18.51H60a7.54 7.54 0 0 1 7.51 7.54z"/>
    </svg>
    {{ $label }}
</a>
