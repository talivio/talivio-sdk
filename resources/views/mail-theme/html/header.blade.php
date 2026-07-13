@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if($logo = config('talivio.mail.brand_logo'))
<img src="{{ $logo }}" height="{{ config('talivio.mail.brand_logo_height', 28) }}" alt="{{ $slot }}" class="header-logo">
@else
<span class="header-product" style="color: {{ config('talivio.mail.brand_color', '#0f172a') }};">{{ $slot }}</span>
@endif
</a>
</td>
</tr>
