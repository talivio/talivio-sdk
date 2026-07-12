@props(['label' => 'Talivio Accounts ile devam et'])
<a href="{{ route('talivio.login') }}"
   {{ $attributes->merge(['class' => 'inline-flex w-full items-center justify-center gap-2.5 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-white/15 dark:bg-white/5 dark:text-slate-100 dark:hover:bg-white/10']) }}>
    <svg width="18" height="18" viewBox="0 0 49.74 51.32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path fill="#3087ce" d="m49.626 11.564a.809.809 0 0 1 .028.209v10.972a.8.8 0 0 1 -.402.694l-9.209 5.302v10.509c0 .286-.152.55-.4.694l-19.223 11.079a.805.805 0 0 1 -.147.06c-.021.007-.04.02-.062.025a.8.8 0 0 1 -.41 0c-.025-.006-.048-.021-.072-.03a.8.8 0 0 1 -.14-.058l-19.219-11.076a.8.8 0 0 1 -.4-.694v-22.155a.8.8 0 0 1 .4-.694l19.219-11.079a.79.79 0 0 1 .753 0z"/>
    </svg>
    {{ $label }}
</a>
