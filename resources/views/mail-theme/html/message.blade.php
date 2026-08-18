{{-- Talivio mail iskeleti — her üründe AYNI. Laravel'in varsayılanından
     farkı: marka adı/URL'i ortam değişkeninden değil marka
     yapılandırmasından okunur.

     ⚠️ config('app.name') DOĞRUDAN KULLANILMAZ: o değer ortamdan gelir ve
     yerelde "Laravel" olabiliyor; maillerin altında "© 2026 Laravel"
     çıkıyordu. `talivio.mail.product_name` varsayılan olarak APP_NAME'e
     düşer ama ürün gerçek adını tek satırla sabitleyebilir. --}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="\Talivio\Sdk\Mail\MailBrand::productUrl()">
{{ \Talivio\Sdk\Mail\MailBrand::productName() }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ \Talivio\Sdk\Mail\MailBrand::legal()['company'] }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
