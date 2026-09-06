# talivio/sdk

One package that gives a Laravel application everything it needs to be part of
the Talivio platform:

- **Talivio Accounts SSO** — a single sign-on button, account linking, and a
  GDPR account-deletion webhook.
- **Central telemetry** — errors, support tickets, heartbeats, and business
  events flow to the Talivio hub with no per-product wiring.
- **Talivio AI gateway client** — one text/embedding client that degrades
  gracefully instead of throwing when the gateway is unreachable.
- **Shared design language** — the Talivio look, footer, logo, and mail theme,
  so every product stays visually consistent from one source.
- **Human check** — behavioural bot protection for public forms, with no
  third-party service.
- **Security response headers** — one nonce-based Content-Security-Policy for
  every product, instead of a permissive copy per repository.
- **Infrastructure clients** — Namecheap/Openprovider, Cloudflare, Ploi and
  mailcow behind four contracts, so a product provisions a customer domain
  without owning a single vendor integration.

Every feature is optional. Install the package, use the parts you need; nothing
autoloads unless you touch it.

> **Licence:** this repository is publicly readable but **not open source**.
> See [LICENSE](LICENSE) before using any part of it outside Talivio.

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| Laravel | 10, 11, 12, or 13 |
| Optional | Laravel Socialite (SSO), Tailwind CSS (design language) |

The plain-PHP footer and logo helpers work without Laravel.

## Installation

### 1. Register the product

Create the application record in the Talivio hub admin. It issues an OAuth
client and an ingest token — both are shown **once**, at creation time.

### 2. Require the package

`talivio/sdk` is **not published on Packagist**. Pin this repository as a VCS
source in `composer.json` *before* requiring it:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/talivio/talivio-sdk.git" }
],
"require": {
    "talivio/sdk": "^1.23"
}
```

> ⚠️ **The `repositories` block is not optional — it is the
> dependency-confusion guard.** Composer resolves a package name against every
> repository it knows, Packagist included, and the first match wins. Without
> this pin, anyone who registers the name `talivio/sdk` on Packagist would be
> installed instead of this package, and `composer install` would report
> nothing unusual. Never run `composer require talivio/sdk` before adding it.

```bash
composer require talivio/sdk
php artisan vendor:publish --tag=talivio-migrations
php artisan vendor:publish --tag=talivio-config
php artisan migrate
```

### 3. Configure the environment

```dotenv
TALIVIO_HUB_URL=https://talivio.com
TALIVIO_CLIENT_ID=...
TALIVIO_CLIENT_SECRET=...
TALIVIO_INGEST_TOKEN=...
TALIVIO_WEBHOOK_SECRET=...
```

No credential is ever stored in this package. Every secret is read from the
host application's environment at runtime.

> ⚠️ **Without `TALIVIO_INGEST_TOKEN`, telemetry is completely silent** — it
> raises no error and writes no log, and the product simply never appears in
> the hub. This failure mode is easy to miss for months. Verify the wiring once
> after install:
>
> ```bash
> php artisan talivio:heartbeat -v
> ```

`TALIVIO_WEBHOOK_SECRET` signs the GDPR deletion callback. If it is unset, the
package falls back to verifying with `TALIVIO_INGEST_TOKEN` (legacy behaviour),
but the two should be separate: the hub stores ingest tokens hashed and allows
them to be rotated, and a rotation would otherwise invalidate in-flight
deletion callbacks.

### 4. Add the sign-in button

```blade
<x-talivio::accounts-button />
```

Error telemetry and the heartbeat need **no further code** — the package hooks
into the application's own exception handler and registers its own schedule
entry (every five minutes). Do not add a `talivio:heartbeat` line to
`routes/console.php`; it would collide with the package's schedule and send the
heartbeat twice. To take the schedule over yourself, set
`heartbeat_schedule => false` in `config/talivio.php`.

That is the whole install: sign-in button, error reporting, and support tickets
all appear in the hub.

## Talivio Accounts (SSO)

| Route | Purpose |
|---|---|
| `GET /talivio/login` | Start the OAuth redirect |
| `GET /talivio/callback` | Handle the OAuth return |
| `GET /talivio/link` | Link Talivio Accounts to the signed-in local user |
| `POST /talivio/unlink` | Sever the link, keep the local account |
| `POST /talivio/account-deleted` | GDPR cascade from the hub (HMAC-signed) |

Blade components:

```blade
<x-talivio::accounts-button />   {{-- login / register screens --}}
<x-talivio::link-account />      {{-- account settings screen --}}
<x-talivio::support-form />      {{-- support ticket form, anywhere --}}
```

`<x-talivio::support-form :priority="true" />` adds a priority selector.

The identity is stored on the user model in the `talivio_id` column, created by
the published migration. Change the guard, user model, or post-login redirect in
`config/talivio.php`.

## Business events

Errors and support tickets flow on their own. **Business events are sent by the
product** — they feed the hub's membership, subscription, sales, and signup
lists, and the revenue figures on its dashboard.

```php
use Talivio\Sdk\Facades\TalivioOps;

// In your payment provider's webhook handler:
TalivioOps::sale([
    'external_id' => $charge->id,      // required — deduplication key
    'status' => 'paid',                 // required
    'customer_email' => $charge->email,
    'amount' => $charge->amount / 100,
    'currency' => 'EUR',
    'provider' => 'stripe',
    'purchased_at' => now(),
]);
```

| Method | Endpoint | Required fields |
|---|---|---|
| `TalivioOps::member()` | `/api/ingest/members` | `external_id`, `kind`, `status` |
| `TalivioOps::subscription()` | `/api/ingest/subscriptions` | `external_id`, `status` |
| `TalivioOps::sale()` | `/api/ingest/sales` | `external_id`, `status` |
| `TalivioOps::signupApplication()` | `/api/ingest/signup-applications` | `external_id`, `email`, `full_name`, `status` |

`kind` accepts `signup`, `application`, `install`, or `manual`.

All four **upsert** on `(application_id, external_id)`, so re-sending the same
event on every state change is safe and never duplicates a record.

**Error handling is deliberately split in two:** a missing required field throws
`InvalidArgumentException`, because that is a programming error and should
surface in development; a network or HTTP failure returns `false` and is logged,
because the hub being down must never break your payment flow.

`status` is free-form text, but the hub's filters and revenue totals resolve it
against a known vocabulary. A spelling outside that vocabulary still stores the
record, but it will **not** count toward MRR — ask for a new status to be added
before inventing one.

## Talivio AI

A single client for text generation and embeddings, pointed at the Talivio AI
gateway. Provider keys live only in the gateway; the product holds nothing but
the gateway key.

```bash
php artisan vendor:publish --tag=talivio-ai-config
```

```dotenv
TALIVIO_AI_BASE_URL=https://ai-gateway.talivio.com
TALIVIO_AI_KEY=...
TALIVIO_AI_DRIVER=http     # use `fake` in the test environment
```

```php
use Talivio\Sdk\Ai\TalivioAi;

$result = app(TalivioAi::class)->chat([
    ['role' => 'user', 'content' => 'Summarise this invoice.'],
], ['tier' => 'standard']);

if ($result->ok) {
    echo $result->text;
}
```

`chatJson()` requests structured output and `AiResult::json()` decodes it —
returning `null` on invalid JSON, which the caller must handle. `embed()`
returns vectors.

Three rules shape this client:

1. **Tier, not model name.** You ask for a quality tier (`standard`, and the
   other tiers the gateway publishes); the gateway picks the model. Model choice
   is measured centrally instead of being updated in every product separately.

2. **No method ever throws.** The gateway is a single door, and its failure must
   not take the product down. Every call returns an `AiResult`; check
   `$result->ok` and `$result->degraded`.

3. **There is no direct-to-provider path.** If the gateway is unreachable the
   product continues without AI. `config/talivio-ai.php` → `degradation.mode`
   decides how: `disable` (the feature quietly turns off — the default),
   `template` (a pre-written non-AI fallback), or `queue` (retry later). A
   `throw` mode is deliberately absent.

A circuit breaker stops calling a failing gateway after
`degradation.breaker_failures` consecutive errors, so an outage does not add
latency to every request.

### Migrating an existing AI client

If your product already has its own provider client, name it and the package
wires a **shadow run**: both paths execute, outputs are compared, and
`migration.primary` decides which result is actually used.

```dotenv
TALIVIO_AI_LEGACY_CLIENT="App\Services\AI\GeminiClient"
TALIVIO_AI_PRIMARY=legacy
TALIVIO_AI_SHADOW=true
TALIVIO_AI_PROBE_SCHEDULE=true   # run the comparison twice daily
```

> ⚠️ **A shadow run bills every call twice.** It is temporary: once the
> comparison is clean, set `primary=gateway`, turn the shadow off, and delete
> the legacy client. The cost is visible on purpose.

```bash
php artisan talivio:ai-migration-probe    # collect samples
php artisan talivio:ai-migration-report   # read the comparison
```

## Design language

The Talivio visual language — cut corners rather than rounded ones, shadow
rather than borders, a slowly drifting blue glow, and the standard footer —
lives in this package so every product shares one source.

### CSS

In the product's `resources/css/app.css`, **after** `@import 'tailwindcss';`:

```css
@import '../../vendor/talivio/sdk/resources/css/talivio-design.css';
```

That provides `cut` / `cut-sm` / `cut-lg` (with focus ring), `lift` / `lift-sm`,
`glow`, `field`, and `btn-primary`, each with a dark-theme counterpart.

> ⚠️ **If the product already has its own button and form system**, import
> `talivio-primitives.css` instead. It contains everything above **except**
> `field` and `btn-primary`:
>
> ```css
> @import '../../vendor/talivio/sdk/resources/css/talivio-primitives.css';
> ```
>
> The split exists because this package's `.btn-primary` is `w-full`. When it
> collides with a product's own narrower button rule, every primary button
> silently stretches to full width.

Usage pattern — **shadow on the outside, clipping on the inside**, because the
shadow of a clipped element is clipped away with it:

```html
<div class="lift"><div class="cut bg-white p-6 dark:bg-neutral-900">…</div></div>
```

Further pitfalls are documented as comments at the top of `talivio-design.css`;
read them before applying the language to a new screen.

On Tailwind v3, `@layer components` and `@apply` behave the same — set
`darkMode: ['class', '[data-theme="dark"]']` in `tailwind.config.js`.

Products that theme the brand colour should define `--color-brand-50…900` in
their `@theme` block. Without them the focus ring falls back to Talivio blue and
`text-brand-300` simply inherits its parent colour.

### Scanning the package views is mandatory

Add this to `app.css` as well:

```css
@source '../../vendor/talivio/sdk/resources/views/**/*.blade.php';
```

Tailwind only emits classes it has seen in scanned source. Relying on
`storage/framework/views` is not enough: if a package component has not been
compiled into that cache at build time, its classes never reach the CSS and the
component renders half-styled — a white button with white text is the usual
symptom. Scanning the package source directly makes the output independent of
the view cache.

### Footer

```blade
<x-talivio::footer product="Product Name" :tagline="__('ui.tagline')">
    <x-slot:links>
        <li><a href="{{ route('dashboard') }}" class="transition hover:text-brand-300">Dashboard</a></li>
    </x-slot:links>

    {{-- optional: the product's own locale/theme switchers --}}
    <x-slot:switches>
        <x-locale-switch :up="true" />
    </x-slot:switches>
</x-talivio::footer>
```

Per-product: `product`, `tagline`, the `links` slot (omit it and the whole
PRODUCT column disappears rather than showing an empty heading), the `switches`
slot, and the audit badge tokens. Fixed inside the component: the legal column,
contact details, address, social accounts, and the copyright bar.

**Audit badges only render when the product passes its own token:**

```blade
<x-talivio::footer product="Product Name"
    security-token="YOUR_TCSR_TOKEN"
    a11yproof-token="YOUR_A11YPROOF_TOKEN"
    privascan-token="YOUR_PRIVASCAN_TOKEN" />
```

The tokens are public status references, not secrets — each one resolves to a
live status page. A badge without a token would be a security claim that does
not resolve, so a product with no token shows no badge.

There is deliberately **no newsletter box** in the component: a form with no
list and no endpoint behind it means never reaching the people who subscribe.

Footer texts come from the package's own `talivio::footer.*` translations
(English, Turkish, Estonian). To reword them:

```bash
php artisan vendor:publish --tag=talivio-lang
```

### Logo

```blade
<x-talivio::logo class="h-8 w-8" />                 {{-- follows the theme --}}
<x-talivio::logo class="h-8 w-8" :on-dark="true" /> {{-- always white --}}
```

Use `on-dark` on surfaces that are dark in **every** theme, such as the footer
strip or a dark hero band. Without it, the mark follows the theme and comes out
black on black.

### Non-Laravel PHP surfaces

Plain-PHP sites consume the same CSS from `vendor/`. Blade components do not
work there, so the package ships plain-PHP equivalents that read the **same**
`lang/*/footer.php` texts:

```php
$talivioFooter = [
    'product' => 'Product Name',
    'tagline' => '…',
    'locale'  => 'en',
    'links'   => [['href' => '/gateway', 'label' => 'Gateway']],
];
require $vendorPath.'/talivio/sdk/resources/plain/footer.php';

$talivioLogo = ['class' => 'h-8 w-8', 'onDark' => true];
require $vendorPath.'/talivio/sdk/resources/plain/logo.php';
```

Their Tailwind build must scan
`vendor/talivio/sdk/resources/plain/**/*.php` for the same reason as above.

### Mail theme

Registered automatically: every Markdown mailable and notification (password
resets, verification, receipts) picks up the shared Talivio look, while a
product's own `resources/views/vendor/mail` overrides still win — publishing
Laravel's stock mail views therefore *disables* this theme, so delete them if
you want the shared look back.

Every product sends the same shaped mail: Talivio logo on the left of the
header, **the product name on the right**, the body, then a legal footer row
carrying the company identity and the Talivio mark. Only `TALIVIO_MAIL_PRODUCT`
is worth setting per product; everything else has a working default.

Values resolve as **published config → env → package default**
(`Talivio\Sdk\Mail\MailBrand`), so a product that published an older
`config/talivio.php` gets the full look from `composer update` alone — no
config edit needed.

```dotenv
TALIVIO_MAIL_PRODUCT=Contentio                     # right-hand header cell + logo alt text
TALIVIO_MAIL_PRODUCT_URL=https://contentio.talivio.com
TALIVIO_MAIL_SUPPORT=contentio@talivio.com         # the mailto: behind the header, and the reply-to a human reads

# Optional overrides
TALIVIO_MAIL_LOGO=https://example.com/logo.png     # PNG/JPG — SVG is unreliable in mail clients
TALIVIO_MAIL_LOGO_HEIGHT=40
TALIVIO_MAIL_COLOR=#0f172a
TALIVIO_MAIL_HEADER_RIGHT=Support                  # talivio.com's corporate mail only
TALIVIO_MAIL_LEGAL_COMPANY="Talivio Technology OÜ"
TALIVIO_MAIL_LEGAL_ADDRESS="Ahtri tn 12, Kesklinna linnaosa, 15551 Tallinn, Estonia"
TALIVIO_MAIL_LEGAL_VAT=EE102744206
```

The legal footer is not decoration: in the EU the sender's legal identity and
address must appear in commercial e-mail.

## GDPR account deletion

When a user permanently deletes their Talivio account, the hub POSTs to this
product's `/talivio/account-deleted` endpoint, registered automatically by the
package. The request is signed with HMAC-SHA256 over `"{timestamp}.{body}"`
using `TALIVIO_WEBHOOK_SECRET`, with a ±5 minute timestamp tolerance against
replays. If that key is unset, verification falls back to
`TALIVIO_INGEST_TOKEN`; both are tried, so products can be updated in any order.

The default behaviour is to **delete** the local user whose `talivio_id`
matches. If your product's local accounts own business records that must survive
— orders, invoices — switch to unlink-only:

```dotenv
TALIVIO_DELETION_BEHAVIOR=unlink
```

`unlink` keeps the local account but clears its `talivio_id`, severing the SSO
link. The endpoint is idempotent: retries and unknown IDs return success.

## Human check

Behavioural bot protection for public forms, without Google reCAPTCHA,
Cloudflare Turnstile, or any other third party. Two pieces:

```blade
<form method="POST" action="{{ route('register') }}">
    @csrf
    ...
    <x-talivio::human-check />   {{-- INSIDE the form --}}
</form>
```

```php
$request->validate([
    ...
    'talivio_human' => [new \Talivio\Sdk\Human\Rules\Human],
]);
```

**How it works.** The component prints a signed, session-bound, single-use token
and an invisible honeypot field. An inline vanilla-JS collector observes pointer
movement (distance and direction changes), touch (`touchstart` / `touchmove` —
this is what makes it work on mobile), accelerometer noise, keystroke count and
interval variance, focus, and click signals — and submits only **aggregate
counters and variances**. No coordinate stream, no key content, no
fingerprinting. The `Human` rule validates the token (HMAC, TTL, session match,
replay prevention, minimum elapsed time) and scores the signals; a request below
`min_score` is rejected.

Scoring is pointer-type aware: no mouse signal is expected on a touch device,
and a keyboard-only accessibility user can pass on key and focus signals alone.
A `navigator.webdriver` flag or a payload-free (JavaScript-disabled) request is
rejected outright.

A user with JavaScript disabled sees the component's `<noscript>` warning
**before** filling the form, and both the warning and the error message point to
the support channel so the action can be completed manually — a deliberate
escape hatch for accessibility and against lock-out.

The token is single-use but **only burns once the submission actually
succeeds**. If another field fails validation — a blank field, a password
mismatch, an email already in use — the same token can be retried. Otherwise the
protection would lock out real users who make a typo rather than bots.

Settings (`config/talivio.php` → `human`): `TALIVIO_HUMAN_ENABLED`,
`TALIVIO_HUMAN_MIN_SECONDS` (default 3), `TALIVIO_HUMAN_MIN_SCORE` (default 3),
`TALIVIO_HUMAN_TOKEN_TTL`, `TALIVIO_HUMAN_LOG_ONLY`.

**Roll it out in shadow mode first.** Set `TALIVIO_HUMAN_LOG_ONLY=true` on a
live product, watch the score distribution in the logs (`talivio.human: …`),
then switch to enforcing. The rule is skipped automatically during test runs
(`enforce_in_tests` turns it back on), so existing registration tests keep
passing.

### Livewire / Volt

A Livewire form does not POST classically — component properties reach the
server, but the value of a hidden DOM field does not. Three things are needed:

```blade
<form wire:submit="register">
    ...
    <x-talivio::human-check livewire />
</form>
```

```php
public string $talivio_human = '';
public string $tl_website = '';   // honeypot

$this->validate([
    // The honeypot is passed as an ARGUMENT: in a Livewire request `tl_website`
    // does not arrive as a plain input, so the rule cannot read it off the request.
    'talivio_human' => [new \Talivio\Sdk\Human\Rules\Human($this->tl_website)],
]);
```

> ⚠️ **Do not use `wire:model.live`.** The collector refreshes its value
> throughout the interaction, so every refresh would become a network request.
> Deferred (default) `wire:model` sends the value with the next action, which is
> the behaviour you want.

### Inertia / Vue / React

Products that build their own form in JavaScript use the ESM module instead of
the Blade component. Both run the **same** collector core, so behaviour is
identical:

```js
import { createHumanCheck } from '../../vendor/talivio/sdk/resources/js/talivio-human.js';

const human = createHumanCheck();   // fetches the token from GET /talivio/human/token

const submit = () => {
    form.talivio_human = human.payload();
    form.post(route('register'));
};
```

The server side is unchanged (`'talivio_human' => [new Human]`); the honeypot
arrives as an ordinary request input, so no argument is needed. Remember to add
the honeypot field to the form state (`tl_website: ''`).

### Threat model

This layer stops commodity bots that script-fill forms, and headless browsers.
It does **not** stop a targeted attacker who writes site-specific code to mimic
human movement — throttling and email verification are the layers for that.
Native mobile apps cannot use the web component; `HumanCheck::verify()` is
transport-independent, so a client producing the same payload shape can be
verified over an API.

## Security response headers

`Talivio\Sdk\Http\Middleware\SecurityHeaders` sends the response headers a
hardened site is expected to send, with a **nonce-based** Content-Security-Policy.

It exists because the permissive version had been copied into four products
(`talivio.com`, `canopyproof`, `vatlio`, `restockio`) and was missing from the
rest. On 2026-09-06 our own scanner (TCSR) dropped twelve `*.talivio.com` sites
from A to C in one night on the same two findings — *"CSP allows scripts from
any/broad origin"* and *"CSP allows 'unsafe-inline' scripts"*. A policy that
lives in twelve places gets fixed in one of them.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(prepend: [\Talivio\Sdk\Http\Middleware\SecurityHeaders::class]);
})
```

**`prepend`, not `append`.** Prepended, the middleware is outermost, so on the
way back out it sees the finished response — including the `XSRF-TOKEN` cookie
that `ValidateCsrfToken` queues. Appended, that cookie does not exist yet and
`harden_xsrf_cookie` silently does nothing.

The middleware never overwrites a header the response already carries — with
one exception. Several of our sites have nginx sending `X-Frame-Options` and
`X-Content-Type-Options`, and a duplicated `X-Frame-Options` is treated as
invalid by some browsers.

The exception is the CSP itself, which this middleware sets unconditionally.
Two policies on one response are a conflict, not a preference, and silently
losing that conflict is the worst outcome: in four of our Shopify apps the
vendor package's `IframeProtection` sets its own (frame-ancestors-only,
nonce-less) policy further in, and a middleware that yields to it looks
migrated while shipping nothing. Being outermost is the whole point of
`prepend`. A product that wants to own its own policy sets
`talivio.security.csp.enabled` to false and writes it itself.

It
deliberately does **not** send `X-Frame-Options` at all — CSP `frame-ancestors`
covers clickjacking and can say the one thing XFO cannot, which our Shopify
embedded apps need: "allow `admin.shopify.com` to frame me".

### The nonce

Every inline `<script>` carries the request's nonce; nothing else runs.

```blade
<script @talivioNonce>
    document.getElementById('x').hidden = true;
</script>
```

`@vite(...)` needs no change — the middleware hands the nonce to Laravel's Vite
helper before the response is built, so the generated tags get it themselves.
The nonce is bound `scoped`, so it is regenerated for each request.

### Adding what your product loads

Two knobs, for two different needs. `sources` **adds** to a directive (what most
products need); `directives` **replaces** one outright (when the default is
simply wrong for this product).

```php
// config/talivio.php
'security' => [
    'csp' => [
        'sources' => [
            'script-src' => ['https://js.stripe.com'],
            'frame-src' => ['https://www.youtube-nocookie.com'],
        ],
        // Shopify embedded app: it must be framable by the merchant's admin.
        'directives' => [
            'frame-ancestors' => ['https://admin.shopify.com', 'https://*.myshopify.com'],
        ],
    ],
],
```

Every value also has a default **inside** the middleware, read through
`config($key, $default)`. Products that published `config/talivio.php` before
this section existed keep working without republishing it.

### Rolling it out without breaking the site

Set `TALIVIO_CSP_REPORT_ONLY=true` first: the policy is sent as
`Content-Security-Policy-Report-Only`, nothing is blocked, and the browser
console names every source you forgot. Flip it off once the console is quiet.

`style-src` keeps `'unsafe-inline'` on purpose. Alpine (`x-show`), Livewire and
many libraries write to `element.style`, which is governed by `style-src-attr`
and cannot be signed with a nonce. Inline *style* does not carry the risk inline
*script* does; `script-src` is where the policy is strict.

`'strict-dynamic'` is opt-in (`TALIVIO_CSP_STRICT_DYNAMIC=true`). With it on,
CSP3 browsers ignore the origin allowlist in `script-src` entirely, so every
`<script src>` without a nonce goes silent. Turn it on per product, after its
script tags are all nonced.

### Alpine and Livewire

Livewire generates its own `<script>` tags, so the product cannot put a nonce on
them; the middleware hands Livewire the nonce the same way it hands it to Vite.
Nothing to do in the product.

Alpine is the one real tax. It compiles inline expressions (`x-data="{ open:
false }"`) with `new Function`, which needs `'unsafe-eval'` — without it every
Alpine page dies silently. Set `TALIVIO_CSP_UNSAFE_EVAL=true` for those
products. It is not the same cost as `'unsafe-inline'`: an injected `<script>`
still cannot run, because it has no nonce. Only the page's own code gains the
right to build code from a string.

The way out is Livewire 4's CSP-safe Alpine build (`livewire.csp_safe`), but it
does not merely remove the need for `'unsafe-eval'` — it removes inline
expressions altogether, so every `x-data` has to become a registered
`Alpine.data()` component first. Do that per product, then drop the flag.

## Infrastructure clients (domains, DNS, hosting, mail)

`Talivio\Sdk\Infra` is the one place the platform talks to its vendors. Every
Talivio product runs on the same server and uses the same four accounts, so
the clients — and the IP-allowlist lessons they encode — live here instead of
being copied into each product.

| Contract | Driver | Purpose |
|---|---|---|
| `Infra\Contracts\Registrar` | Namecheap (default), Openprovider | availability + quote, register, renew, transfer in, nameservers, transfer lock |
| `Infra\Contracts\Dns` | Cloudflare | zone per customer domain, apex/www records, arbitrary record upserts, zone lookup by suffix |
| `Infra\Contracts\Host` | Ploi | attach a domain to the product's site, request/poll its certificate; create/delete whole sites for ops |
| `Infra\Contracts\ProductMail` | Mailio | **what a product uses for mail** — provision domains, mailboxes and aliases for its own customers, scoped to them |
| `Infra\Contracts\Mail` | mailcow | the raw shared mail host: domains, mailboxes, aliases, quota and usage, the MX/SPF/DMARC/DKIM records a domain needs. Mailio and ops tooling only |
| `Infra\Contracts\DnsProbe` | PHP's resolver | reads **public** DNS — A, CNAME, TXT — for "does the world see this yet?"; no credentials, no driver choice |

`Host::listSites()` also carries ops-inventory fields (status, project_type,
php_version, system_user, last_deploy_at, disk_usage_mb, has_repository,
created_at) beyond id/domain/aliases, and `Host::siteCertificates()` lists
every certificate on a site (domains, status, expiry) — Ploi has no
server-level certificate endpoint, so an inventory reads them per site.

Type-hint the contract and the package binds the configured driver. Missing
credentials fail at resolution with a `NotConfiguredException` naming the
environment variable; a product that wants to *show* "not configured" instead
calls the concrete class's `fromConfig()`, which returns `null`.

```php
use Talivio\Sdk\Infra\Contracts\{Registrar, Dns, Host};

public function __construct(private Registrar $registrar, private Dns $dns, private Host $host) {}

$zone = $this->dns->ensureZone($domain);                       // ['id', 'nameservers', 'active']
$id   = $this->registrar->register($domain, $registrant, $zone['nameservers']);
$this->dns->ensureRecords($zone['id'], $domain, $this->host->serverIp());
$this->host->attachDomain($domain);
$this->host->requestCertificate($domain, ["www.{$domain}"], $webhookUrl, validateViaDns: true);
```

```dotenv
DOMAIN_REGISTRAR=namecheap          # or openprovider
DOMAIN_MARGIN_PERCENT=20            # Talivio's markup on the reseller quote; 0 = pass-through
DOMAIN_SUPPORTED_TLDS=com,net,org   # empty = every ending the account can sell

NAMECHEAP_API_KEY=… NAMECHEAP_USERNAME=… NAMECHEAP_CLIENT_IP=…   # NAMECHEAP_SANDBOX=true in dev
OPENPROVIDER_USERNAME=… OPENPROVIDER_PASSWORD=…
CLOUDFLARE_API_TOKEN=… CLOUDFLARE_ACCOUNT_ID=…                   # scoped token; the global key + CLOUDFLARE_EMAIL also works
PLOI_API_TOKEN=… PLOI_SERVER_ID=… PLOI_SITE_ID=…                 # PLOI_ATTACH_MODE=tenant|alias — pick once, never switch
MAILCOW_URL=… MAILCOW_API_KEY=… MAILCOW_MX_HOST=… MAILCOW_SPF_VALUE=…
```

**Mail has two layers, and a product wants the upper one.**
`Infra\Contracts\ProductMail` goes through Mailio, which is the
owner-of-record for every mail package Talivio sells; every call is scoped to
the calling product's own customers. `Infra\Contracts\Mail` is the raw shared
mailcow instance and reaches domains no product owns — it is for Mailio
itself and for ops tooling.

```php
use Talivio\Sdk\Infra\Contracts\ProductMail;

public function __construct(private ProductMail $mail) {}

$this->mail->createDomain("site-{$site->id}", $domain, ['label' => $site->name, 'mailboxes' => 10]);
foreach ($this->mail->dnsRecords($domain) as $record) { /* show, or publish via Dns */ }
$this->mail->verifyDomain($domain);        // once the ownership TXT is live
$this->mail->createMailbox($domain, 'info', $password);
```

The key is issued by Mailio (`php artisan mailio:mail-key <product>`) and goes
in the product's `.env` as `TALIVIO_MAIL_KEY`. There is no "which product am
I" argument anywhere in the surface: the key answers that, so a compromised
product cannot act as another. Bind `Infra\Testing\FakeProductMail` in tests —
it enforces the same rules the gateway does, so a test cannot pass on a flow
production refuses.

One mailcow instance serves every product, so a mail domain says who owns
it in the host's own description field:

```php
use Talivio\Sdk\Infra\Support\MailOwner;

$mail->addDomain($domain, active: false, owner: new MailOwner('mailio', "company-{$company->id}", $company->name));
$mail->setDomainActive($domain, true);        // once the DNS proof lands

MailOwner::fromDescription($row['description']);   // null = legacy/unknown, NOT unowned
```

A domain is created switched **off** whenever ownership isn't proven yet:
mailcow treats a local domain as authoritative and would otherwise swallow
mail belonging to its real owner. Mailio is the owner-of-record for mail
packages across all products; other products should route domain and
mailbox lifecycle through it rather than editing the shared instance
directly.

Things every driver's docblock repeats because they cost real time to learn:
Namecheap sends `ClientIp` with every call and the IP must be whitelisted on
the account; Ploi and mailcow tokens carry an IP allowlist too, and a 403 there
is not a scope problem; the Namecheap sandbox is a separate account with its
own key; Ploi's alias endpoint *replaces* the whole list; Cloudflare's DNS-01
validation at Ploi only works with a scoped token, never the global key.

### Proving a customer owns a domain

`Dns` is the control plane of the zones we run; `DnsProbe` is what the
internet currently answers, which is a different fact and usually the one a
verification flow needs. `Support\DomainOwnership` is the check Shops and
Contentio had each written out separately:

```php
use Talivio\Sdk\Infra\Support\DomainOwnership;

public function __construct(private DomainOwnership $ownership) {}

$this->ownership->tokenHost($domain);                    // _talivio-verify.acme.com — show this
$this->ownership->cnamePointsAtAny($domain, $accepted);  // $accepted = current platform domain + legacy ones
$this->ownership->tokenIsPublished($domain, $token);
$this->ownership->verify($domain, $token, $accepted);    // both, when you don't need to say which half failed
```

**Both halves are required.** A CNAME only shows the domain routes here, and
its target is public — printed in the product's own UI — so any other tenant
could add the same domain string and pass that check the moment the real owner
points it at us for their own reasons. The per-claim token is shown to one
tenant only and can only be published by whoever controls the domain's DNS.

Two neighbouring checks that deliberately are **not** this one: nameserver
delegation needs no token at all (only the registrant can repoint NS, so
`Dns::zoneIsActive()` is itself the proof), and mail ownership is Mailio's own
apex TXT with no CNAME involved, because a mail domain routes nothing to us.

Product test suites bind the in-memory fakes so nothing reaches a vendor:

```php
$this->app->instance(Registrar::class, $this->registrar = new \Talivio\Sdk\Infra\Testing\FakeRegistrar);
$this->app->instance(Dns::class, $this->dns = new \Talivio\Sdk\Infra\Testing\FakeDns);
$this->app->instance(Host::class, $this->host = new \Talivio\Sdk\Infra\Testing\FakeHost);
$this->app->instance(Mail::class, $this->mail = new \Talivio\Sdk\Infra\Testing\FakeMail);
$this->app->instance(ProductMail::class, $this->productMail = new \Talivio\Sdk\Infra\Testing\FakeProductMail);
$this->app->instance(DnsProbe::class, (new \Talivio\Sdk\Infra\Testing\FakeDnsProbe)
    ->withCname('acme.com', ['shops.talivio.com'])
    ->publishToken('acme.com', $domain->verification_token));
```

Each fake records what it was asked and exposes knobs (`$taken`, `$issueOnRequest`,
`$newZonesActive`, `$failWith`, …) to simulate the slow and failing paths.

### Developing the package

```bash
composer install
composer test    # PHPUnit through Orchestra Testbench, every HTTP call faked
composer lint    # Laravel Pint, check only
```

## Security

No credentials are stored in this repository — see [SECURITY.md](SECURITY.md)
for the vulnerability reporting process.

## Licence

Copyright © 2026 Talivio Technology OÜ. All rights reserved.

This repository is published for transparency and security review. It is **not**
open source, and reading it grants no right to use it outside Talivio. See
[LICENSE](LICENSE) for the exact terms, or contact info@talivio.com for
licensing enquiries.
