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
  Ploi tokens and mailcow have IP allowlists too. One server
  (`esiteks.talivio.com`), one outbound IPv4 — **confirmed 2026-09-05:
  `31.220.77.127`** (checked both from the `talivio` app user and directly
  on the host; both agree) — so one allowlist entry per service (Namecheap
  ClientIp whitelist, Ploi token's IP allowlist, mailcow's "Allow from")
  covers every product that will ever call these clients.

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

## Mail: Mailio is the owner-of-record (2026-09-05, Eren's decision)

Every mail package Talivio sells in ANY product is a Mailio package, so the
`Mail` contract grew to the full mailcow admin surface in v1.26.0 and Mailio
deleted its own 490-line `MailcowService` (v1.26.2 in Mailio). What stayed in
Mailio is what is NOT a mail-host concern: `MailDomainVerifier` (proves domain
ownership against public DNS — the host is TOLD the answer via
`setDomainActive`, it does not compute it) and `MailDnsGuide` (the customer's
record table; the SDK returns data, the prose and its translations belong to
the product).

- **`MailOwner` stamps ownership into mailcow's own description field**
  (`Acme Ltd [mailio:company-7]`). One instance serves every product, so
  "whose domain is this?" must be answerable from the mail server itself.
  A description with no tag parses to null = **unknown/legacy, NOT unowned**.
- **`HostRefusedException` vs plain RuntimeException**: a refusal carries a
  reason the customer should read and retrying never helps; anything else
  means the host was unreachable. Products must branch on this — Mailio's
  `callMailcow()` is the reference.
- **Mailcow env name differs from Mailio's old one**: the SDK reads
  `MAILCOW_URL` = the INSTANCE ROOT (`https://mail.talivio.com`) and appends
  `/api/v1/...` itself. Mailio's dead block used `MAILCOW_API_URL` = the API
  root. Same key name `MAILCOW_API_KEY`.

⚠️ **The shared instance carries real customer mail: 25 domains, 118
mailboxes** (measured 2026-09-05). All were created by hand over the years
with the description set to the domain name, and no product's database knows
about them — Contentio's tables say 0 provisioned domains, Mailio's say 0.
**They were all attributed on 2026-09-05** via `mailio:mail-owners --apply`:
`talivio.com` (39 mailboxes, Talivio's own corporate mail) as
`talivio:internal`, one domain that turned out to be a Contentio site as
`contentio:site-N`, and the remaining 23 as a blanket `ops:manual`.
⚠️ Those 23 are NOT all the same thing — some take only WordPress hosting,
some another Talivio service, some only mail. The per-domain breakdown is
customer data, so it lives OUT of this public repo, in
`Mailio/laravel/.claude/memory/mail-domain-attribution.md` (gitignored);
Eren will supply the split and each one gets retagged to its real owner.
⚠️ **This repository is publicly readable** — never write customer names,
domain lists or who-pays-for-what into it. Verified before/after:
25→25 domains, 118→118 mailboxes, 118 still active, and NO field other than
`description` changed — mailcow's `edit/domain` does not reset unspecified
attributes (checked field-by-field on one domain first, then across all 25).
A pre-write snapshot lives at `storage/mailcow-domains-before.json` on
mailio.talivio.com. Contentio's own tables say 0 provisioned domains and 0
mailboxes; Mailio's say 0/0/1-company. So real customer mail is running on an
instance that no product's database knows about. Consequences: (a) the
`deleteDomain`/`setDomainActive` powers on the shared contract are genuinely
dangerous on this instance, (b) `ops:manual` is a REAL answer meaning "a human
runs this, no product automation owns it" — product code must refuse to touch
a domain whose tag is not its own, (c) `talivio:internal` is the one domain
whose loss would take down every product's outbound mail identity.

**Backfill tool (2026-09-05):** `php artisan mailio:mail-owners` on
mailio.talivio.com audits every domain of the shared instance and, with
`--apply`, stamps an owner tag. Read-only by default; never deletes or
deactivates; only edits the description; idempotent; `--claim=product:ref`
(default `ops:manual`), `--only=`, `--retag`. It cannot run until Mailio's
`.env` gets `MAILCOW_URL` (INSTANCE root) + `MAILCOW_API_KEY` — Mailio's old
block used `MAILCOW_API_URL` = the API root, so the value changes shape, not
just the key name.

## `ProductMail` consumers (v1.28.1)

Both products a mail package can be sold from now inject
`Infra\Contracts\ProductMail` and neither has a mailcow client any more.
`Contracts\Mail` is for Mailio itself and ops tooling only — it reaches the
whole shared instance, hand-run domains included.

- **Contentio** 2026-09-05, `customer_ref = "site-{id}"`.
- **Shops** 2026-09-05, `customer_ref = "store-{id}"`. Its own
  `MailProvider`/`MailcowProvider` pair, `MAILCOW_*` config and unit test are
  deleted; 1766 tests green.

Two shapes worth reusing, because the swap is not one-for-one:

- **`createDomain()` is not idempotent and an unverified domain cannot hold
  addresses**, where the old `ensureDomain()` was one safe-to-repeat call. Both
  products wrap it: look the domain up first, register only if absent, publish
  Mailio's records into our own zone when we run it, then re-verify. Shops keeps
  this in `Modules\Email\Support\MailDomains`; every mailbox/alias creation goes
  through it.
- **Where the product runs the zone, publish the ownership TXT yourself** — the
  customer then never sees a second verification step at all. Both products key
  this off their own "we run this zone" column (`dns_zone_id` in Shops). On a
  customer-run zone they need a "check DNS again" action, because the record is
  theirs to publish and propagation takes a moment either way.

⚠️ Neither product has `TALIVIO_MAIL_KEY` in production, so both resolve
`UnconfiguredProductMail`: pages render, provisioning refuses. Nothing regressed
— neither had a working mailcow key either. Issuing a key is Eren's to do
(`php artisan mailio:mail-key <product>`), and its output must not reach a chat
or a log.

## Domain ownership: one proof, three former copies (v1.29.0)

`Infra\Contracts\DnsProbe` reads PUBLIC DNS (A/CNAME/TXT); `Support\SystemDnsProbe`
is the `dns_get_record` implementation, bound as a singleton; `Testing\FakeDnsProbe`
replaces the hand-rolled doubles Shops, Contentio and talivio.com each had.
`Support\DomainOwnership` carries the policy: a CNAME at an accepted platform host
AND the per-claim token at `_talivio-verify.{domain}`.

- **Not `Dns`.** That contract is the control plane of zones we run and knows what
  we *intended*; `DnsProbe` knows what the internet *answers*. A customer's own
  zone answers here and not there, and a zone we edited a second ago answers there
  and not yet here.
- **No credentials, no driver, no `Unconfigured` variant** — that is why it sits
  outside `registerInfra()`'s driver table.
- **Every lookup answers "nothing" rather than throwing.** These calls sit behind a
  customer-facing button and inside scheduled sweeps; a briefly unhappy resolver is
  a "not yet".
- **Accepted CNAME targets stay in the product** (current platform domain + legacy
  ones): that is config, not policy. Contentio keeps it in
  `DomainController::acceptedCnameTargets()`, static so the scheduled re-check
  cannot drift from the panel button; Shops reads it inline in `verifyByProof()`.
- **Shops keeps the two halves separate** instead of calling `verify()`, so the
  merchant is told which record is missing.

Three neighbouring checks that are deliberately NOT this one:
NS delegation needs no token (only the registrant can repoint NS, so
`Dns::zoneIsActive()` is the proof); mail ownership is Mailio's own apex TXT with
its own token derivation and no CNAME (`MailDomainVerifier`, stays in Mailio);
Contentio's Cloudflare-cloud CNAME hides its target from public DNS, so
`cnamePointsAtAny()` would never see it.

Consumers: Shops 6300bb0, Contentio 3f9e9a0 (+6 new tests for a verify path that
had none — it could not be tested before without live DNS), talivio.com 43c7cfe.
