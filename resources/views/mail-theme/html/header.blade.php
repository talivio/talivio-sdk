@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="header-product">{{ $slot }}</span>
</a>
</td>
</tr>
