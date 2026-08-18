@props(['url'])
{{-- Mail başlığı: SOLDA Talivio logosu, SAĞDA ÜRÜN ADI.

     ⚠️ ORTALI DEĞİL, İKİ UÇLU. Eskiden logo ortadaydı ve tek başınaydı;
     mailin kimden geldiği belliydi ama hangi ÜRÜNDEN geldiği yalnızca
     gönderen adresinden anlaşılıyordu. Ürün adı, mailin en çok bakılan
     yerinde — üstte ve sağda — dursun.

     ⚠️ SAĞ HÜCRE ÜRÜN ADIDIR, "Support" DEĞİL (2026-08-18 kararı): tek bir
     "Support" etiketi 30+ üründen gelen maili birbirinden ayırt edilemez
     kılıyordu. Destek adresi hâlâ bağlantının kendisinde (`mailto:`) ve
     mailin altındaki künyede duruyor. talivio.com kendi kurumsal
     maillerinde `talivio.mail.header_right` ile bunu "Support" olarak
     geçersiz kılabilir — orada ürün ADI zaten soldaki logoda yazıyor.

     ⚠️ Ölçüler tabloyla verilir, flexbox ile değil: Outlook (Word motoru)
     flex/grid tanımaz; e-postada tablo hâlâ tek güvenilir yerleşim aracı. --}}
<tr>
<td class="header">
{{-- ⚠️ GENİŞLİK 570px, %100 DEĞİL: içerik kartıyla AYNI ölçü (.inner-body).
     %100 bırakıldığında logo ve sağdaki etiket kartın dışına, mailin en uç
     kenarlarına taşıyordu — iki uçlu yerleşimin ilk denemesinde görüldü. --}}
<table class="header-inner" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="left" class="header-brand-cell">
<a href="{{ $url }}" style="display: inline-block;">
@if($logo = config('talivio.mail.brand_logo'))
<img src="{{ $logo }}" height="{{ config('talivio.mail.brand_logo_height', 40) }}" alt="{{ $slot }}" class="header-logo">
@else
<span class="header-product" style="color: {{ config('talivio.mail.brand_color', '#0f172a') }};">{{ $slot }}</span>
@endif
</a>
</td>
<td align="right" class="header-support-cell">
@php($headerRight = config('talivio.mail.header_right') ?: trim($slot))
@if($headerRight !== '')
<a href="mailto:{{ config('talivio.mail.support_email') }}" class="header-support">{{ $headerRight }}</a>
@endif
</td>
</tr>
</table>
</td>
</tr>
