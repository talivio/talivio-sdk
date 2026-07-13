@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="header-product">{{ $slot }}</span>
</a>
<div class="header-tagline">a <span class="header-talivio">Talivio</span> product</div>
</td>
</tr>
