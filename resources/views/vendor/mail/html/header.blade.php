@props(['url'])
{{-- The wordmark is text, not the site's SVG: Outlook and several webmail
     clients will not render an inline SVG, and a remote image is blocked by
     default in most of them. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">{!! $slot !!}</a>
</td>
</tr>
