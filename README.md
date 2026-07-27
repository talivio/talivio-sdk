# talivio/sdk

Talivio Accounts SSO + central error/support telemetry for every Talivio product. Ports the same revenue-engine pattern used across Talivio's SaaS apps: one package, drop it in, done.

## Install (per product)

1. **Register the product in talivio.com** — `/admin/applications` → create → fill name + URL. This provisions an OAuth client and an ingest token, shown once.

2. **Add the package** (path repository until this is on a private Packagist):

```json
"repositories": [
    { "type": "path", "url": "../talivio-sdk" }
],
"require": {
    "talivio/sdk": "*"
}
```

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
```

4. **Add the button** to your login/register Blade views:

```blade
<x-talivio::accounts-button />
```

5. **Error telemetry and heartbeat** work with zero extra code — the package hooks into your app's own exception handler automatically. Schedule the heartbeat in `routes/console.php`:

```php
Schedule::command('talivio:heartbeat')->everyFiveMinutes();
```

6. **Support form** (optional): drop `<x-talivio::support-form />` anywhere.

That's it — login button, error logs, and support tickets all show up in talivio.com's "Talivio Accounts" / "Talivio Ops" admin panels.

## Design language (from v1.12.0)

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

That gives you `cut` / `cut-sm` / `cut-lg`, `lift` / `lift-sm`, `glow`, `field`
and `btn-primary`, with dark-theme counterparts. Usage pattern — **shadow on the
outside, clipping on the inside** (the shadow of a clipped element is clipped
away too):

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
`"{timestamp}.{body}"` using the product's ingest token as the shared secret,
with a ±5 minute timestamp tolerance against replays.

Default behavior is to **delete** the local user whose `talivio_id` matches.
If your product's local accounts own business records that must survive
(orders, invoices), switch to unlink-only:

```
TALIVIO_DELETION_BEHAVIOR=unlink
```

`unlink` keeps the local account but clears its `talivio_id`, severing the SSO
link. The endpoint is idempotent — retries and unknown IDs return success.
