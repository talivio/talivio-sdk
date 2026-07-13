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
</td>
</tr>
</table>
</td>
</tr>
