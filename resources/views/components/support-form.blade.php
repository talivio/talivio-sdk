@props(['title' => 'Destek talebi oluşturun'])
<div {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-white/5']) }}>
    <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>

    @if(session('status') === 'support-ticket-sent')
        <p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
            Talebiniz alındı, en kısa sürede dönüş yapacağız.
        </p>
    @endif

    <form method="POST" action="{{ route('talivio.support.store') }}" class="mt-4 space-y-3">
        @csrf
        <input type="text" name="name" placeholder="Ad Soyad" required
               value="{{ old('name', auth()->user()->name ?? '') }}"
               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
        <input type="email" name="email" placeholder="E-posta" required
               value="{{ old('email', auth()->user()->email ?? '') }}"
               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
        <input type="text" name="subject" placeholder="Konu" required
               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
        <textarea name="message" placeholder="Mesajınız" required rows="4"
                  class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
        @error('message')
            <p class="text-sm text-rose-600">{{ $message }}</p>
        @enderror
        <button type="submit" class="rounded-lg bg-talivio-600 px-4 py-2 text-sm font-semibold text-white hover:bg-talivio-700">
            Gönder
        </button>
    </form>
</div>
