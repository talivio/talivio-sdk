{{--
    Talivio markası — TEK KAYNAK (`<x-talivio::logo />`).

    Marka SVG'si şimdiye kadar her üründe, hatta aynı ürünün üç ekranında elle
    kopyalanıyordu; ilk renk değişikliğinde kopyalar birbirinden sapardı.

    ⚠️ `onDark`: markanın koyu parçaları normalde temaya göre renk değiştirir,
    ama alt bilgi ve koyu şeritler HER TEMADA koyu zeminlidir — orada tema
    kuralına uymak, açık temada siyah üstüne siyah bir logo demek. ai.talivio.com
    alt bilgisinde tam olarak bu görüldü. Koyu zemine basarken:

        <x-talivio::logo class="h-8 w-8" :on-dark="true" />

    ⚠️ `dark:fill-white` varyantı ürünün kendi koyu tema yapılandırmasına bakar
    (`data-theme` ya da `.dark`); `onDark` ise yapılandırmadan BAĞIMSIZ, koşulsuz
    beyaz basar. İkisi farklı sorulara cevap veriyor: "tema koyu mu?" ve
    "zemin koyu mu?".

    ⚠️ Bu dosyanın sınıflarının CSS'e girmesi için ürünün `app.css` dosyasında
    SDK view dizinini tarayan `@source` satırı olmak ZORUNDA (README).
--}}
@props(['onDark' => false])

<svg viewBox="0 0 101.67 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
     {{ $attributes->merge(['class' => 'h-6 w-6']) }}>
    <path fill="#0072CE" opacity=".8" d="M67.5 59.12V33.33H83a23.1 23.1 0 0 1 18.63 18.51v14.83H75a7.55 7.55 0 0 1-7.5-7.55z"/>
    <path class="{{ $onDark ? 'fill-white' : 'fill-neutral-900 dark:fill-white' }}" d="M34.16 25.79V0h31.91a4.67 4.67 0 0 1 3.3 1.36l30.94 31a4.66 4.66 0 0 1 1.36 3.29v16.19A23.1 23.1 0 0 0 83 33.33H41.71a7.54 7.54 0 0 1-7.55-7.54z"/>
    <path fill="#0072CE" opacity=".8" d="M34.18 40.88v25.79H18.64A23.12 23.12 0 0 1 0 48.16V33.33h26.63a7.55 7.55 0 0 1 7.55 7.55z"/>
    <path class="{{ $onDark ? 'fill-white' : 'fill-neutral-900 dark:fill-white' }}" d="M67.51 74.21V100H35.6a4.66 4.66 0 0 1-3.29-1.36L1.36 67.69A4.66 4.66 0 0 1 0 64.4V48.16a23.12 23.12 0 0 0 18.64 18.51H60a7.54 7.54 0 0 1 7.51 7.54z"/>
</svg>
