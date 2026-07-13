@props(['label' => 'Talivio Accounts ile devam et'])
<a href="{{ route('talivio.login') }}"
   {{ $attributes->merge(['class' => 'inline-flex w-full items-center justify-center gap-2.5 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-white/15 dark:bg-white/5 dark:text-slate-100 dark:hover:bg-white/10']) }}>
    <svg width="18" height="18" viewBox="0 0 101.67 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="dark:hidden">
        <path fill="#0072CE" opacity=".8" d="M67.5 59.12V33.33H83a23.1 23.1 0 0 1 18.63 18.51v14.83H75a7.55 7.55 0 0 1-7.5-7.55z"/>
        <path fill="#000000" d="M34.16 25.79V0h31.91a4.67 4.67 0 0 1 3.3 1.36l30.94 31a4.66 4.66 0 0 1 1.36 3.29v16.19A23.1 23.1 0 0 0 83 33.33H41.71a7.54 7.54 0 0 1-7.55-7.54z"/>
        <path fill="#0072CE" opacity=".8" d="M34.18 40.88v25.79H18.64A23.12 23.12 0 0 1 0 48.16V33.33h26.63a7.55 7.55 0 0 1 7.55 7.55z"/>
        <path fill="#000000" d="M67.51 74.21V100H35.6a4.66 4.66 0 0 1-3.29-1.36L1.36 67.69A4.66 4.66 0 0 1 0 64.4V48.16a23.12 23.12 0 0 0 18.64 18.51H60a7.54 7.54 0 0 1 7.51 7.54z"/>
    </svg>
    <svg width="18" height="18" viewBox="0 0 101.67 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="hidden dark:block">
        <path fill="#ffffff" opacity=".8" d="M67.5 59.12V33.33H83a23.1 23.1 0 0 1 18.63 18.51v14.83H75a7.55 7.55 0 0 1-7.5-7.55z"/>
        <path fill="#0072CE" d="M34.16 25.79V0h31.91a4.67 4.67 0 0 1 3.3 1.36l30.94 31a4.66 4.66 0 0 1 1.36 3.29v16.19A23.1 23.1 0 0 0 83 33.33H41.71a7.54 7.54 0 0 1-7.55-7.54z"/>
        <path fill="#ffffff" opacity=".8" d="M34.18 40.88v25.79H18.64A23.12 23.12 0 0 1 0 48.16V33.33h26.63a7.55 7.55 0 0 1 7.55 7.55z"/>
        <path fill="#0072CE" d="M67.51 74.21V100H35.6a4.66 4.66 0 0 1-3.29-1.36L1.36 67.69A4.66 4.66 0 0 1 0 64.4V48.16a23.12 23.12 0 0 0 18.64 18.51H60a7.54 7.54 0 0 1 7.51 7.54z"/>
    </svg>
    {{ $label }}
</a>
