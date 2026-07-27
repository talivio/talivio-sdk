# talivio/sdk

Talivio Accounts SSO + central error/support telemetry for every Talivio product. Ports the same revenue-engine pattern used across Talivio's SaaS apps: one package, drop it in, done.

## Install (per product)

1. **Register the product in talivio.com** — `/admin/applications` → create → fill name + URL. This provisions an OAuth client and an ingest token, shown once.

2. **Add the package** — pin the exact GitHub URL as a VCS repository:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/talivio/talivio-sdk.git" }
],
"require": {
    "talivio/sdk": "^1.17"
}
```

⚠️ **The `repositories` block above is not optional — it is the dependency-confusion
guard.** `talivio/sdk` is **not published on Packagist**. Composer resolves package
names from every repository it knows about, including Packagist, unless a name match
in an earlier entry wins first. Without the VCS pin, a future Packagist account
claiming the name `talivio/sdk` would be installed silently instead of this package —
`composer install` gives no warning either way. Every product in this org already
pins this repository entry; do not `composer require talivio/sdk` without it first.

```bash
composer require talivio/sdk
php artisan vendor:publish --tag=talivio-migrations
php artisan vendor:publish --tag=talivio-config
php artisan migrate
```

3. **.env**:

```
TALIVIO_HUB_URL=https://talivio.com
TALIVIO_CLIENT_ID=...
TALIVIO_CLIENT_SECRET=...
TALIVIO_INGEST_TOKEN=...
TALIVIO_WEBHOOK_SECRET=...
```

⚠️ **`TALIVIO_INGEST_TOKEN` olmadan telemetri tamamen sessizdir** — hata da log
da üretmez, ürün panelde hiç var olmamış gibi görünür. Denetimde 11 ürünün SDK'yı
kurup bu anahtarı hiç tanımlamadığı, dolayısıyla aylardır hiçbir şey göndermediği
ortaya çıktı. Kurulumdan sonra `php artisan talivio:heartbeat -v` ile bir kez
doğrulayın.

`TALIVIO_WEBHOOK_SECRET` GDPR silme çağrısının imza anahtarıdır. Tanımlanmazsa
`TALIVIO_INGEST_TOKEN`'a geri düşülür (eski davranış), ama hub artık ingest
token'ını hash'li sakladığı ve döndürülebilir yaptığı için ayrı bir anahtar
kullanmak gerekir.

4. **Add the button** to your login/register Blade views:

```blade
<x-talivio::accounts-button />
```

5. **Error telemetry and heartbeat** work with zero extra code — the package
hooks into your app's own exception handler automatically, **and schedules the
heartbeat itself** (v1.10.0'dan beri, 5 dakikada bir). `routes/console.php`'ye
elle satır eklemeyin: SDK'nın zamanlamasıyla çakışır ve heartbeat iki kez gider.
Zamanlamayı devralmak isterseniz `config/talivio.php`'de
`heartbeat_schedule => false` yapın.

6. **Support form** (optional): drop `<x-talivio::support-form />` anywhere.
`:priority="true"` verirseniz öncelik seçici de çıkar.

That's it — login button, error logs, and support tickets all show up in talivio.com's "Talivio Accounts" / "Talivio Ops" admin panels.

## İş olayları — üyelik, abonelik, satış, başvuru

Hata ve destek telemetrisi kendiliğinden akar; **iş olaylarını ürün gönderir.**
Bunlar panelin "Talivio Ops" bölümündeki Üyelikler / Abonelikler / Satışlar /
Üyelik Başvuruları listelerini ve dashboard'daki gelir kutucuklarını besler.

```php
use Talivio\Sdk\Facades\TalivioOps;

// Ödeme sağlayıcınızın webhook handler'ında:
TalivioOps::sale([
    'external_id' => $charge->id,      // zorunlu — tekilleştirme anahtarı
    'status' => 'paid',                 // zorunlu
    'customer_email' => $charge->email,
    'amount' => $charge->amount / 100,
    'currency' => 'EUR',
    'provider' => 'stripe',
    'purchased_at' => now(),
]);
```

Dört metot da `(application_id, external_id)` üzerinden **upsert** yapar: aynı
olayı her durum değişiminde yeniden göndermek güvenlidir, çift kayıt oluşmaz.

| Metot | Uç | Zorunlu alanlar |
|---|---|---|
| `TalivioOps::member()` | `/api/ingest/members` | `external_id`, `kind`, `status` |
| `TalivioOps::subscription()` | `/api/ingest/subscriptions` | `external_id`, `status` |
| `TalivioOps::sale()` | `/api/ingest/sales` | `external_id`, `status` |
| `TalivioOps::signupApplication()` | `/api/ingest/signup-applications` | `external_id`, `email`, `full_name`, `status` |

`kind` yalnızca `signup` | `application` | `install` | `manual` olabilir.

**Hata davranışı ikiye ayrılır:** eksik zorunlu alan `InvalidArgumentException`
atar (programlama hatasıdır, geliştirmede görülmeli); ağ/HTTP hatası `false`
döndürür ve loglanır (hub'ın kapalı olması ödeme akışınızı kırmamalı).

`status` ürüne özgü serbest metindir, ama panelin filtreleri ve gelir toplamları
hub'daki `config/talivio-statuses.php` sözlüğüne bakar. Sözlükte olmayan bir
yazım kullanırsanız kayıt görünür ama **MRR toplamına girmez** — yeni bir durum
adı gerekiyorsa önce sözlüğe ekleyin.

## Design language (from v1.12.0, split into primitives + components in v1.17.0)

The Talivio visual language — cut corners instead of rounded ones, shadow
instead of borders, a slowly drifting blue glow, and the standard footer —
lives **in this package**, so all products share one source. The full contract
(rules, pitfalls, footer content) is `talivio-ai/docs/tasarim-dili.md`; the code
here is the implementation of it.

### 1. CSS

In the product's `resources/css/app.css`, **after** `@import 'tailwindcss';`:

```css
@import '../../vendor/talivio/sdk/resources/css/talivio-design.css';
```

That gives you `cut` / `cut-sm` / `cut-lg` (+ focus ring), `lift` / `lift-sm`,
`glow`, `field` and `btn-primary`, with dark-theme counterparts.

⚠️ **If the product already has its own button/form system** (its own
`.btn-primary`, `.input`, etc.), import `talivio-primitives.css` instead — it
has everything above **except** `field` and `btn-primary`:

```css
@import '../../vendor/talivio/sdk/resources/css/talivio-primitives.css';
```

This split exists because `talivio-design.css`'s `.btn-primary` is `w-full`;
a product with its own narrower inline button silently had every primary
button stretch to full width when the two collided (measured in vatlio).

Usage pattern — **shadow on the outside, clipping on the inside** (the shadow
of a clipped element is clipped away too):

```html
<div class="lift"><div class="cut bg-white p-6 dark:bg-neutral-900">…</div></div>
```

The four pitfalls are documented as comments at the top of
`talivio-design.css` — read them before applying the language to a screen.

Tailwind v3 products: `@layer components` and `@apply` work the same; set
`darkMode: ['class', '[data-theme="dark"]']` in `tailwind.config.js`.

Products that theme the brand color should define `--color-brand-50…900` in
their `@theme` block (see ai.talivio.com); without them the focus ring falls
back to Talivio blue and `text-brand-300` simply inherits its parent color.

### 2. ⚠️ Scanning the package views is MANDATORY

Add this line to `app.css` as well:

```css
@source '../../vendor/talivio/sdk/resources/views/**/*.blade.php';
```

Tailwind only emits classes it has seen in scanned source. Relying on
`storage/framework/views` is not enough: if a package component has not been
compiled into that cache at build time, its classes never reach the CSS and the
component renders half-styled. This shipped to production on 2026-07-26 —
a white button with white text on ai.talivio.com. Scanning the package source
directly makes the output independent of the view cache.

### 3. Footer

```blade
<x-talivio::footer product="Talivio AI" :tagline="__('ui.tagline')">
    <x-slot:links>
        <li><a href="{{ route('chat.index') }}" class="transition hover:text-brand-300">Chat</a></li>
    </x-slot:links>

    {{-- optional: the product's own locale/theme switchers --}}
    <x-slot:switches>
        <x-locale-switch :up="true" />
    </x-slot:switches>
</x-talivio::footer>
```

Per-product: `product`, `tagline`, the `links` slot (PRODUCT column — omit it
and the whole column disappears rather than showing an empty heading), the
`switches` slot, and the audit badge tokens. Fixed inside the component: the
LEGAL column, contact details, address, social accounts and the copyright bar.

**Audit badges only render when the product passes its own token:**

```blade
<x-talivio::footer product="Vendio"
    security-token="ucqkvl5ngrtleizeipqvvfrpneli33qs"
    a11yproof-token="x4w5r81b5mclalyuvgt7qz8s"
    privascan-token="qpiwptkg6sycljstc5flxmd6" />
```

A badge without a token is a security claim that does not resolve, so a product
that has no token shows no badge. There is deliberately **no newsletter box** in
the component: a form with no list and no endpoint behind it means never
reaching the people who subscribe.

Footer texts come from the package's own `talivio::footer.*` translations
(tr / en / et). To reword them:

```bash
php artisan vendor:publish --tag=talivio-lang
```

### 4. Logo

```blade
<x-talivio::logo class="h-8 w-8" />              {{-- follows the theme --}}
<x-talivio::logo class="h-8 w-8" :on-dark="true" /> {{-- always white --}}
```

`on-dark` is for surfaces that are dark in **every** theme (the footer strip,
dark hero bands). Without it the mark follows the theme and comes out black on
black — exactly what happened in ai.talivio.com's footer.

## GDPR account deletion (cascade from the hub)

When a user permanently deletes their Talivio account on talivio.com, the hub
POSTs to this product's `/talivio/account-deleted` endpoint (registered
automatically by the SDK). The request is signed with HMAC-SHA256 over
`"{timestamp}.{body}"` using the product's `TALIVIO_WEBHOOK_SECRET` as the
shared secret, with a ±5 minute timestamp tolerance against replays.

Anahtar tanımlı değilse `TALIVIO_INGEST_TOKEN` ile doğrulamaya geri düşülür.
İki anahtar da denendiği için ürünler sıra bağımsız güncellenebilir; ama ingest
token'ı hub'da artık hash'li saklandığı ve döndürülebilir olduğu için geri
dönüş kalıcı bir çözüm değildir — imza anahtarı ayrı olmalı.

Default behavior is to **delete** the local user whose `talivio_id` matches.
If your product's local accounts own business records that must survive
(orders, invoices), switch to unlink-only:

```
TALIVIO_DELETION_BEHAVIOR=unlink
```

`unlink` keeps the local account but clears its `talivio_id`, severing the SSO
link. The endpoint is idempotent — retries and unknown IDs return success.
