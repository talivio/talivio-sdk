---
name: infra-clients-in-sdk
description: "Since v1.25 (2026-09-05) the registrar/DNS/hosting/mail clients (Namecheap, Openprovider, Cloudflare, Ploi, mailcow) live in Talivio\\Sdk\\Infra — products must not re-implement them; what the live environments actually have configured"
metadata:
  type: project
---

As of 2026-09-05 (v1.25.0–1.25.2) the vendor integrations every product needs
for customer domains ship from this package under `Talivio\Sdk\Infra`: four
contracts (`Registrar`, `Dns`, `Host`, `Mail`, plus the optional
`BulkAvailability`), five clients (`Clients\{Namecheap, Openprovider,
Cloudflare, Ploi, Mailcow}`), in-memory fakes under `Infra\Testing`, config
under `talivio.infra.*` (env names from Contentio's old `services.php`; Shops'
spellings `NAMECHEAP_API_USER` / `CLOUDFLARE_API_EMAIL` read as fallbacks).
Shops (Domains module) and Contentio (Domains + Email modules) were migrated
the same day and deleted their copies; talivio.com's `/admin/hosting` ops
page is built on the same contracts. Vebook is NOT migrated (next step).

Decisions worth not re-deriving:

- **Contracts resolve even without credentials** — to an `Unconfigured*`
  stand-in whose calls throw `NotConfiguredException` naming the env keys.
  Failing at resolution (v1.25.0/1) broke every controller that
  constructor-injects a contract; Shops' domain pages 500ed on live.
  `app(Namecheap::class)` (the concrete class) still fails at resolution.
- **A product must type-hint the SDK contracts directly.** A module-local
  interface that merely `extends` the SDK contract is not what the SDK's
  clients implement — Shops' `DomainRegistrar $registrar` received a
  `Namecheap` and threw a TypeError on live; tests missed it because they
  bound their own doubles.
- **Ploi has two attach modes** and a product must never switch: `tenant`
  (default, Shops — tenant certificates, DNS-01 possible) vs `alias`
  (Contentio — site alias list + site certificate). Contentio pins
  `alias` in code (`DomainsServiceProvider`), not in `.env`.
- **mailcow delete/alias wants the alias's numeric id**, not the address
  (Shops found this live 2026-08-30); `add/domain` on an existing domain is
  a "danger" reply, so the client checks `get/domain` first.
- **Namecheap quote = max(register, renew) + ICANN fee**, never the
  first-year promo (renewals reuse the purchase price). Contentio's search
  and checkout now show this (previously the raw register price).
- Namecheap sends `ClientIp` and rejects unlisted IPs (1011102/1011150);
  Ploi tokens and mailcow have IP allowlists too. One server, one outbound
  IP (`esiteks.talivio.com`), so one allowlist entry covers every product.

Live state seen 2026-09-05 (env key NAMES only): Shops has **no**
NAMECHEAP/CLOUDFLARE/PLOI keys (contradicting the Shops memory that said the
Namecheap prod key was configured); Contentio has only MAILCOW_*; talivio.com
has none of them. Until keys are added, the ops page and the products'
domain features show "not configured" — nothing is broken, nothing works
either.

**How to apply:** when a product needs a registrar/DNS/host/mail call,
type-hint the SDK contract (or call `Client::fromConfig()` for a null-able
"is it configured?" check); bind `Infra\Testing\Fake*` in its tests. Add
new vendor logic here, tag, bump the product — never copy a client back
into a product. Package tests: `composer test` (Testbench, all HTTP faked).
