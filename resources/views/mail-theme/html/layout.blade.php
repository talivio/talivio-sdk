<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
.inner-body,
.header-inner {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}

/* ⚠️ DAR EKRANDA BAŞLIK TAŞIYORDU. Logo + support adresi 570px'e göre
   kurulmuş; 380px'lik bir telefonda adres sağ kenardan kırpılıyordu
   (2026-08-16, mobil önizlemede görüldü). Küçültmek, kırpmaktan iyi. */
.header-inner {
padding-left: 12px !important;
padding-right: 12px !important;
}

.header-logo {
height: 32px !important;
}

/* Teklif tablosunun yan boşlukları dar ekranda içeriği sıkıştırıyor. */
.offer-label,
.offer-amount,
.offer-head-label,
.offer-head-amount,
.offer-total-label,
.offer-total-amount,
.offer-subtotal-label,
.offer-subtotal-amount,
.offer-discount-label,
.offer-discount-amount {
padding-left: 12px !important;
padding-right: 12px !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}

{{-- Alt künye: SOLDA yasal bilgi, SAĞDA yalnız Talivio ikonu.

     ⚠️ İKİ SATIR KALDIRILDI ("Bu e-posta … tarafından gönderildi",
     "bir Talivio ürünü"): ikisi de mailin zaten söylediğini tekrar
     ediyordu — üstte logo var, altta şirket künyesi var. Gövdeyi
     uzatan ama hiçbir soruya cevap vermeyen satırlardı.

     ⚠️ YASAL KÜNYE ŞART: AB'de ticari e-posta gönderen tüzel kişinin adı,
     adresi ve vergi kimliği mailde görünmek zorunda. Şirketin Estonya'da
     kayıtlı olması bunu isteğe bağlı yapmıyor. --}}
<table class="legal-row" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="left" class="legal-cell">
{{ config('talivio.mail.legal.company') }} · {{ config('talivio.mail.legal.address') }} · VAT {{ config('talivio.mail.legal.vat_id') }}
</td>
<td align="right" class="legal-icon-cell">
<a href="https://talivio.com"><img src="{{ config('talivio.mail.brand_logo_icon') }}" alt="Talivio" width="32" height="32" class="legal-icon"></a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
