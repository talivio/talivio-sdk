{{-- Talivio marka satırı artık layout.blade.php'de, beyaz içerik alanının
     içinde render ediliyor — burada sadece uygulamanın kendi footer
     içeriği (genelde "© Yıl AppName. All rights reserved.") kalıyor,
     tekrar bir Talivio telif satırı eklenmiyor. --}}
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
