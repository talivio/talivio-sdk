@props(['linked' => null])

@php
    $column = config('talivio.talivio_id_column');
    $user = auth(config('talivio.guard'))->user();
    $isLinked = $linked ?? (bool) ($user->{$column} ?? null);
    $status = session('talivio_status');
@endphp

@if($status === 'linked')
    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
        Talivio Accounts hesabınıza bağlandı.
    </div>
@elseif($status === 'unlinked')
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
        Talivio Accounts bağlantısı kaldırıldı. Artık e-posta ve şifrenizle giriş yapabilirsiniz — şifrenizi bilmiyorsanız "şifremi unuttum" adımını kullanın.
    </div>
@elseif($status === 'link-conflict')
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
        Bu Talivio Accounts hesabı zaten başka bir kullanıcıya bağlı.
    </div>
@elseif($status === 'link-failed')
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
        Talivio Accounts bağlantısı kurulamadı, lütfen tekrar deneyin.
    </div>
@endif

<div class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-white/10">
    <div class="flex items-center gap-3">
        <svg width="28" height="28" viewBox="0 0 101.67 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="dark:hidden">
            <path fill="#0072CE" opacity=".8" d="M67.5 59.12V33.33H83a23.1 23.1 0 0 1 18.63 18.51v14.83H75a7.55 7.55 0 0 1-7.5-7.55z"/>
            <path fill="#000000" d="M34.16 25.79V0h31.91a4.67 4.67 0 0 1 3.3 1.36l30.94 31a4.66 4.66 0 0 1 1.36 3.29v16.19A23.1 23.1 0 0 0 83 33.33H41.71a7.54 7.54 0 0 1-7.55-7.54z"/>
            <path fill="#0072CE" opacity=".8" d="M34.18 40.88v25.79H18.64A23.12 23.12 0 0 1 0 48.16V33.33h26.63a7.55 7.55 0 0 1 7.55 7.55z"/>
            <path fill="#000000" d="M67.51 74.21V100H35.6a4.66 4.66 0 0 1-3.29-1.36L1.36 67.69A4.66 4.66 0 0 1 0 64.4V48.16a23.12 23.12 0 0 0 18.64 18.51H60a7.54 7.54 0 0 1 7.51 7.54z"/>
        </svg>
        <svg width="28" height="28" viewBox="0 0 101.67 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="hidden dark:block">
            <path fill="#ffffff" opacity=".8" d="M67.5 59.12V33.33H83a23.1 23.1 0 0 1 18.63 18.51v14.83H75a7.55 7.55 0 0 1-7.5-7.55z"/>
            <path fill="#0072CE" d="M34.16 25.79V0h31.91a4.67 4.67 0 0 1 3.3 1.36l30.94 31a4.66 4.66 0 0 1 1.36 3.29v16.19A23.1 23.1 0 0 0 83 33.33H41.71a7.54 7.54 0 0 1-7.55-7.54z"/>
            <path fill="#ffffff" opacity=".8" d="M34.18 40.88v25.79H18.64A23.12 23.12 0 0 1 0 48.16V33.33h26.63a7.55 7.55 0 0 1 7.55 7.55z"/>
            <path fill="#0072CE" d="M67.51 74.21V100H35.6a4.66 4.66 0 0 1-3.29-1.36L1.36 67.69A4.66 4.66 0 0 1 0 64.4V48.16a23.12 23.12 0 0 0 18.64 18.51H60a7.54 7.54 0 0 1 7.51 7.54z"/>
        </svg>
        <div>
            <p class="text-sm font-semibold text-slate-900 dark:text-white">Talivio Accounts</p>
            @if($isLinked)
                <p class="text-xs text-emerald-600 dark:text-emerald-400">
                    <i class="fas fa-check-circle"></i> Bağlı
                </p>
            @else
                <p class="text-xs text-slate-500 dark:text-slate-400">Tek tıkla giriş için hesabınızı bağlayın</p>
            @endif
        </div>
    </div>

    @if($isLinked)
        <form method="POST" action="{{ route('talivio.unlink') }}"
              onsubmit="return confirm('Talivio Accounts bağlantısını kaldırmak istediğinize emin misiniz?');">
            @csrf
            <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50 dark:border-white/15 dark:text-slate-300 dark:hover:bg-white/5">
                Bağlantıyı kaldır
            </button>
        </form>
    @else
        <a href="{{ route('talivio.link') }}" class="rounded-lg bg-[#3087ce] px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[#256bad]">
            Bağla
        </a>
    @endif
</div>
