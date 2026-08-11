<?php
/**
 * Talivio standart alt bilgisi — Laravel DIŞI PHP yüzeyler için düz karşılık.
 *
 * Blade ürünler `<x-talivio::footer>` kullanır; bu dosya onunla AYNI yapıyı
 * basar (sözleşme: talivio-ai/docs/tasarim-dili.md §5 + §7): koyu şerit →
 * 4 sütun (marka · ÜRÜN · YASAL · İLETİŞİM) → alt şerit (telif + "Talivio
 * yapımı"). HTML'i KOPYALAMAYIN — bu dosyayı include edin; metinler Blade
 * bileşeniyle AYNI lang/<locale>/footer.php dosyalarından okunur, iki kopya
 * birbirinden sapamaz.
 *
 * Kullanım:
 *     $talivioFooter = [
 *         'product'  => 'MOSA',
 *         'tagline'  => '…',                  // verilmezse paragraf basılmaz
 *         'locale'   => 'tr',                 // lang/<locale>/footer.php; yoksa en
 *         'links'    => [['href' => '…', 'label' => '…']], // boşsa ÜRÜN sütunu basılmaz
 *         'switches' => '<div>…</div>',       // dil/tema seçici HTML'i (ürüne ait)
 *         'securityToken' => null, 'a11yproofToken' => null, 'privascanToken' => null,
 *     ];
 *     require $vendorPath.'/talivio/sdk/resources/plain/footer.php';
 *
 * ⚠️ Blade bileşenindeki yasaklar aynen geçerli: rozet YALNIZCA jetonla
 * basılır, bülten kutusu YOK, uydurma "Dokümantasyon" bağlantısı YOK.
 * ⚠️ Soluk metin `text-neutral-400`, 500 DEĞİL (WCAG AA — footer.blade.php).
 * ⚠️ `switches` HTML'i ürünün sorumluluğudur; kaçışlanmadan basılır.
 */

$tfCfg = $talivioFooter ?? [];

$tfLangDir = __DIR__.'/../../lang/';
$tfLocale  = preg_replace('/[^a-z_]/i', '', (string) ($tfCfg['locale'] ?? 'en'));
$tfLang    = is_file($tfLangDir.$tfLocale.'/footer.php') ? require $tfLangDir.$tfLocale.'/footer.php' : [];
$tfLangEn  = require $tfLangDir.'en/footer.php';
$tfT       = static function (string $key, array $repl = []) use ($tfLang, $tfLangEn): string {
    $s = $tfLang[$key] ?? $tfLangEn[$key] ?? $key;
    foreach ($repl as $k => $v) { $s = str_replace(':'.$k, $v, $s); }
    return htmlspecialchars($s, ENT_QUOTES);
};

$tfBrand = trim((string) ($tfCfg['product'] ?? 'Talivio'));
// "Talivio AI" → "Talivio" beyaz + "AI" marka mavisi; diğer adlar tek parça.
$tfSuffix  = str_starts_with($tfBrand, 'Talivio ') ? substr($tfBrand, 8) : null;
$tfTagline = trim((string) ($tfCfg['tagline'] ?? ''));
$tfLinks   = $tfCfg['links'] ?? [];
$tfSwitches = (string) ($tfCfg['switches'] ?? '');

// Rozetler: jeton → [servis, jeton, başlık anahtarı, alt metin anahtarı].
// Jetonu olmayan servis diziye HİÇ girmez (çalışmayan güvenlik iddiası yasağı).
$tfBadges = array_values(array_filter([
    !empty($tfCfg['securityToken'])  ? ['security',  $tfCfg['securityToken'],  'badge_security_title', 'badge_security_alt'] : null,
    !empty($tfCfg['a11yproofToken']) ? ['a11yproof', $tfCfg['a11yproofToken'], 'badge_a11y_title',     'badge_a11y_alt']     : null,
    !empty($tfCfg['privascanToken']) ? ['privascan', $tfCfg['privascanToken'], 'badge_privacy_title',  'badge_privacy_alt']  : null,
]));

$tfSocial = [
    ['https://www.linkedin.com/company/taliviotechnology/', 'LinkedIn', 'M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-1 1.83-2.05 3.77-2.05C20.5 8.65 21 11 21 14.2V21h-4v-6c0-1.43-.03-3.27-2-3.27-2 0-2.3 1.56-2.3 3.17V21H9z'],
    ['https://www.x.com/taliviotech', 'X', 'M17.5 3h3.2l-7 8 8.2 10h-6.4l-5-6.1L4.7 21H1.5l7.5-8.6L1.2 3h6.6l4.5 5.6zm-1.1 16h1.8L7.7 4.8H5.8z'],
    ['https://www.instagram.com/taliviotechnology', 'Instagram', 'M12 2.2c3.2 0 3.6 0 4.9.07 3.3.15 4.8 1.7 4.9 4.9.06 1.3.07 1.7.07 4.8s0 3.6-.07 4.9c-.15 3.2-1.7 4.8-4.9 4.9-1.3.06-1.7.07-4.9.07s-3.6 0-4.9-.07c-3.3-.15-4.8-1.7-4.9-4.9C2.2 15.6 2.2 15.2 2.2 12s0-3.6.07-4.9c.15-3.2 1.7-4.8 4.9-4.9C8.4 2.2 8.8 2.2 12 2.2zm0 3.2a6.6 6.6 0 1 0 0 13.2 6.6 6.6 0 0 0 0-13.2zm0 10.9a4.3 4.3 0 1 1 0-8.6 4.3 4.3 0 0 1 0 8.6zm6.8-11.1a1.55 1.55 0 1 1-3.1 0 1.55 1.55 0 0 1 3.1 0z'],
];
?>
<footer class="mt-20 border-t border-white/5 bg-neutral-950 text-neutral-400">
    <div class="mx-auto max-w-6xl px-5 pt-14 pb-8">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-4">

            <div>
                <div class="flex items-center gap-2.5">
                    <?php $talivioLogo = ['class' => 'h-8 w-8', 'onDark' => true]; require __DIR__.'/logo.php'; ?>
                    <span class="text-lg font-bold text-white">
                        <?php if ($tfSuffix !== null): ?>
                            Talivio <span class="font-medium text-brand-300"><?= htmlspecialchars($tfSuffix, ENT_QUOTES) ?></span>
                        <?php else: ?>
                            <?= htmlspecialchars($tfBrand, ENT_QUOTES) ?>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if ($tfTagline !== ''): ?>
                    <p class="mt-4 max-w-xs text-sm leading-relaxed"><?= htmlspecialchars($tfTagline, ENT_QUOTES) ?></p>
                <?php endif; ?>

                <div class="mt-5 flex items-center gap-2" aria-label="<?= $tfT('follow') ?>">
                    <?php foreach ($tfSocial as [$tfHref, $tfLabel, $tfPath]): ?>
                        <a href="<?= $tfHref ?>" aria-label="<?= $tfLabel ?>" target="_blank" rel="noopener"
                           class="cut-sm bg-white/5 p-2 text-neutral-400 transition hover:bg-white/10 hover:text-white">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="<?= $tfPath ?>"/></svg>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($tfBadges !== []): ?>
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <?php foreach ($tfBadges as [$tfService, $tfToken, $tfTitleKey, $tfAltKey]): ?>
                            <a href="https://<?= $tfService ?>.talivio.com/status/<?= rawurlencode($tfToken) ?>"
                               target="_blank" rel="noopener" class="group inline-flex items-center"
                               title="<?= $tfT($tfTitleKey) ?>">
                                <img src="https://<?= $tfService ?>.talivio.com/badge/<?= rawurlencode($tfToken) ?>.svg"
                                     alt="<?= $tfT($tfAltKey, ['product' => $tfBrand]) ?>"
                                     width="140" height="36" loading="lazy"
                                     class="h-9 w-auto opacity-90 transition-opacity group-hover:opacity-100">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tfSwitches !== ''): ?>
                    <div class="mt-5 -ml-3 flex flex-wrap items-center gap-1"><?= $tfSwitches ?></div>
                <?php endif; ?>
            </div>

            <?php if ($tfLinks !== []): ?>
                <div>
                    <p class="text-sm font-bold tracking-wider text-neutral-200 uppercase"><?= $tfT('product') ?></p>
                    <ul class="mt-4 space-y-3 text-sm">
                        <?php foreach ($tfLinks as $tfLink): ?>
                            <li><a href="<?= htmlspecialchars($tfLink['href'], ENT_QUOTES) ?>" class="transition hover:text-brand-300"><?= htmlspecialchars($tfLink['label'], ENT_QUOTES) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div>
                <p class="text-sm font-bold tracking-wider text-neutral-200 uppercase"><?= $tfT('legal') ?></p>
                <ul class="mt-4 space-y-3 text-sm">
                    <li><a href="https://talivio.com/privacy" class="transition hover:text-brand-300"><?= $tfT('privacy') ?></a></li>
                    <li><a href="https://talivio.com/terms" class="transition hover:text-brand-300"><?= $tfT('terms') ?></a></li>
                    <li><a href="https://talivio.com/impressum" class="transition hover:text-brand-300"><?= $tfT('impressum') ?></a></li>
                </ul>
            </div>

            <div>
                <p class="text-sm font-bold tracking-wider text-neutral-200 uppercase"><?= $tfT('contact') ?></p>
                <ul class="mt-4 space-y-3 text-sm">
                    <li><a href="tel:+37282721454" class="transition hover:text-brand-300">+372 8272 1454</a></li>
                    <li><a href="mailto:info@talivio.com" class="transition hover:text-brand-300">info@talivio.com</a></li>
                    <li class="leading-relaxed text-neutral-400">Harju, Tallinn, Ahtri tn 12, 15551</li>
                </ul>
            </div>
        </div>

        <div class="mt-14 flex flex-col items-center justify-between gap-3 border-t border-white/10 pt-8 sm:flex-row">
            <p class="text-sm text-neutral-400"><?= $tfT('rights_company', ['year' => date('Y')]) ?></p>

            <p class="flex items-center gap-2 text-xs text-neutral-400">
                <?= $tfT('made_by') ?>
                <a href="https://talivio.com" class="inline-flex items-center gap-1.5 font-semibold text-neutral-300 transition hover:text-white">
                    <?php $talivioLogo = ['class' => 'h-4 w-4', 'onDark' => true]; require __DIR__.'/logo.php'; ?>
                    Talivio
                </a>
            </p>
        </div>
    </div>
</footer>
<?php unset($talivioFooter, $tfCfg, $tfLangDir, $tfLocale, $tfLang, $tfLangEn, $tfT, $tfBrand, $tfSuffix, $tfTagline, $tfLinks, $tfSwitches, $tfBadges, $tfSocial); ?>
