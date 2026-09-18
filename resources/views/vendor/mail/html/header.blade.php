@props(['url'])
{{-- The app's logo badge beside its name, as on the sidebar and the client's wallet page.
     Laid out as a table because Outlook ignores flexbox. The badge is a hosted PNG: Gmail
     drops inline SVG and data: URIs. asset() resolves against APP_URL in the queue worker,
     so the image comes from the same domain as the wallet link. --}}
<tr>
<td class="header">
<table class="brand" align="center" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-mark"><a href="{{ $url }}"><img src="{{ asset('images/email/logo.png') }}" width="36" height="36" alt="" class="brand-logo"></a></td>
<td class="brand-name"><a href="{{ $url }}">{!! $slot !!}</a></td>
</tr>
</table>
</td>
</tr>
