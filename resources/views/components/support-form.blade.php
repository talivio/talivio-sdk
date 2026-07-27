@props(['title' => 'Destek talebi oluşturun', 'priority' => false])
@php
    // auth()->user() DEĞİL: SupportFormController kullanıcıyı
    // `config('talivio.guard')` üzerinden çözüyor. Varsayılan guard'ı `web`
    // olmayan bir üründe form boş dolar, talep yine de doğru kullanıcıya
    // bağlanırdı — iki taraf da aynı guard'a bakmalı.
    $talivioUser = auth()->guard(config('talivio.guard'))->user();
@endphp
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
               value="{{ old('name', $talivioUser->name ?? '') }}"
               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
        <input type="email" name="email" placeholder="E-posta" required
               value="{{ old('email', $talivioUser->email ?? '') }}"
               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
        <input type="text" name="subject" placeholder="Konu" required
               value="{{ old('subject') }}"
               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
        @if($priority)
            {{-- Hem SDK hem hub `priority` alanını doğruluyordu ama formda hiç yoktu. --}}
            <select name="priority"
                    class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
                <option value="low" @selected(old('priority') === 'low')>Düşük</option>
                <option value="normal" @selected(old('priority', 'normal') === 'normal')>Normal</option>
                <option value="high" @selected(old('priority') === 'high')>Yüksek</option>
                <option value="urgent" @selected(old('priority') === 'urgent')>Acil</option>
            </select>
        @endif
        <textarea name="message" placeholder="Mesajınız" required rows="4"
                  class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">{{ old('message') }}</textarea>
        @error('message')
            <p class="text-sm text-rose-600">{{ $message }}</p>
        @enderror
        <button type="submit" class="rounded-lg bg-talivio-600 px-4 py-2 text-sm font-semibold text-white hover:bg-talivio-700">
            Gönder
        </button>
    </form>
</div>
