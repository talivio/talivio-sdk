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
        <svg width="28" height="28" viewBox="0 0 49.74 51.32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path fill="#3087ce" d="m49.626 11.564a.809.809 0 0 1 .028.209v10.972a.8.8 0 0 1 -.402.694l-9.209 5.302v10.509c0 .286-.152.55-.4.694l-19.223 11.079a.805.805 0 0 1 -.147.06c-.021.007-.04.02-.062.025a.8.8 0 0 1 -.41 0c-.025-.006-.048-.021-.072-.03a.8.8 0 0 1 -.14-.058l-19.219-11.076a.8.8 0 0 1 -.4-.694v-22.155a.8.8 0 0 1 .4-.694l19.219-11.079a.79.79 0 0 1 .753 0z"/>
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
