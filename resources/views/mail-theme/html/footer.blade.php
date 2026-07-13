<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
{{ Illuminate\Mail\Markdown::parse($slot) }}
<p class="footer-brand">
© {{ date('Y') }} <a href="https://talivio.com" class="footer-link">Talivio Technology OÜ</a> — Tallinn, Estonia<br>
Bu e-posta {{ config('app.name') }} tarafından gönderildi.
</p>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="right" class="footer-tagline-cell">
<a href="https://talivio.com" class="footer-tagline">a <img src="https://talivio.com/assets/images/icon-logo.png" alt="" width="16" height="16" class="footer-tagline-icon"> <span class="footer-talivio">Talivio</span> product</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
