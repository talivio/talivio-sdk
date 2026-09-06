# Changelog

Releases are git tags on this repository (`vX.Y.Z`); products pin them through
Composer. History before 1.25.0 is in the commit log, where each release commit
carries its version in parentheses.

## 1.30.0 - 2026-09-06

- **`Http\Middleware\SecurityHeaders` + `Http\Security\Csp` — one
  nonce-based Content-Security-Policy for every product.** The permissive
  version had been copied into four products and was missing from the rest;
  on 2026-09-06 TCSR dropped twelve `*.talivio.com` sites from A to C in one
  night on the same two findings it raises against that policy shape.
- The policy names no wildcard and no bare `https:`, and `script-src` carries
  neither `'unsafe-inline'` nor `'unsafe-eval'` — inline scripts are signed
  with a per-request nonce instead (`@talivioNonce`, and `@vite` tags get it
  automatically through Laravel's Vite helper). A test asserts the generated
  policy against TCSR's own two regexes, so the package cannot drift back into
  producing a finding.
- `style-src` keeps `'unsafe-inline'` deliberately: Alpine, Livewire and
  friends write to `element.style`, which `style-src-attr` governs and a nonce
  cannot sign. Inline style is not the risk inline script is.
- `'strict-dynamic'` and report-only mode are both opt-in
  (`TALIVIO_CSP_STRICT_DYNAMIC`, `TALIVIO_CSP_REPORT_ONLY`). Report-only is how
  a product is meant to adopt this without breaking a page it forgot about.
- Products add what they load through `talivio.security.csp.sources` (append to
  a directive) or `.directives` (replace one — how the Shopify apps allow
  `admin.shopify.com` to frame them). Every key also has a default inside the
  middleware, so products that published `config/talivio.php` earlier keep
  working without republishing it.
- The middleware never overwrites a header the response already has (nginx
  already sends some of them on these hosts) and sends no `X-Frame-Options` at
  all: `frame-ancestors` covers clickjacking and can express what XFO cannot.
- Register it with `prepend:`, not `append:` — outermost is the only position
  from which it can see the queued `XSRF-TOKEN` cookie.

## 1.29.0 - 2026-09-05

- **`DnsProbe` + `Support\DomainOwnership` — proving a customer owns a
  domain, once.** Shops and Contentio had each written the same two-part
  check (a CNAME at the platform plus a per-claim TXT token at
  `_talivio-verify.{domain}`), and talivio.com had a third copy of the raw
  lookup wrapper underneath it. The policy and its reasoning now live in one
  place, with the products keeping their own customer-facing wording.
- `DnsProbe` reads PUBLIC DNS and is deliberately not `Dns`: that contract is
  the control plane of the zones we run and knows what we intended, this one
  knows what the internet currently answers. It takes no credentials, has no
  driver choice and cannot be "unconfigured"; `Support\SystemDnsProbe` is
  bound as a singleton.
- Every lookup answers "nothing" instead of throwing. These calls sit behind
  a button a customer clicks and inside scheduled sweeps, where a briefly
  unhappy resolver is a "not yet".
- `Testing\FakeDnsProbe` replaces three hand-rolled test doubles, and matches
  hosts case-insensitively with the trailing dot stripped — the way a real
  answer arrives, so a test cannot pass only for the exact string it typed.
- Mail ownership stays in Mailio (`MailDomainVerifier`): apex TXT, its own
  token derivation, no CNAME. It is a different question, not a fourth copy.

## 1.28.1 - 2026-09-05

- Documents 1.28.0 in the README and this file; no code change. (The 1.28.0
  tag shipped the code without its documentation.)

## 1.28.0 - 2026-09-05

- **`ProductMail` — the contract a product should actually use for mail.**
  It goes through Mailio's server-to-server API, which is the owner-of-record
  for every mail package Talivio sells, and scopes every call to the calling
  product's own customers. `Mail` stays the raw shared mailcow instance,
  which reaches domains no product owns (25 hand-run customer domains and 118
  mailboxes live there) and is now documented as being for Mailio and ops
  tooling only.
- `Clients\MailioGateway` implements it over HTTP, `Testing\FakeProductMail`
  in memory — enforcing the same rules the gateway does, so a product test
  cannot pass on a flow production refuses — and
  `Support\UnconfiguredProductMail` keeps resolution working without a key.
- Identity is the credential: there is no "which product am I" argument in
  the surface, so a compromised product cannot impersonate another. Reads
  retry on 5xx, writes never do — a resent create could charge a plan slot
  twice or resurrect an address the customer just deleted.

## 1.27.0 - 2026-09-05

- **`Mail::setDomainOwner()`** re-stamps who a domain belongs to without
  touching anything else about it. Needed to attribute domains created
  before the convention existed, and to hand one owner's domain to another.
  The wire format stays inside the package: callers pass a `MailOwner`,
  never a hand-built description string.

## 1.26.2 - 2026-09-05

- **`HostRefusedException`** separates "the vendor answered NO" from "the
  vendor could not be reached". They need opposite handling: a refusal
  carries a reason the customer should read (weak password, quota
  exceeded, duplicate) and retrying never helps; an outage deserves "try
  again in a moment" and no detail. Both used to arrive as plain
  RuntimeException, so a product either leaked transport errors to
  customers or replaced real refusal reasons with a misleading "server
  unreachable" - Mailio hit the second case. mailcow raises it; existing
  `catch (RuntimeException)` blocks keep working, since it extends that.

## 1.26.1 — 2026-09-05

- `Mail::updateMailbox()` accepts `forward_to` and `forward_only`, the mailbox
  forwarding mailcow supports and Mailio's "forward a copy elsewhere" screen
  needs. `forward_to` replaces the whole list and is read with
  `array_key_exists`, so passing `[]` clears forwarding instead of being
  skipped as an absent key.

## 1.26.0 — 2026-09-05

- **`Mail` grows to the full mail-host admin surface**, because Mailio is now
  the owner-of-record for every mail package Talivio sells in any product
  (2026-09-05 decision) and it needs more than "add a domain, add a mailbox":
  `listDomains`, `domain`, `setDomainActive`, `mailbox`, `updateMailbox`,
  `setMailboxesActive`, `mailboxQuota`, `resourceSummary`, `deleteAliasById`,
  `updateAlias`, `countAliases`, and the sync-job trio used to migrate a
  customer's mail in from their old provider. `addDomain` gains `active`,
  `owner`, `defaultQuotaMb`, `totalQuotaMb` and `maxAliases`; `addMailbox`
  gains `quotaMb`. Every addition is optional and positional-safe, so the
  calls Contentio and talivio.com already make are unchanged.
- **`MailOwner` stamps ownership into the mail host itself** (`Acme Ltd
  [mailio:company-7]` in mailcow's description field). One instance serves
  every product; before this, "who owns this domain?" could only be answered
  by querying each product's database in turn, and a domain outliving its
  product became unattributable. A description with no tag parses to null,
  which means "unknown/legacy" — never "unowned, safe to delete".
- `addDomain(active: false)` creates a domain switched OFF, for the
  provable-ownership flow: mailcow treats a local domain as authoritative
  and would otherwise swallow mail belonging to its real owner.

## 1.25.2 — 2026-09-05

- An Infra contract now RESOLVES in an environment without credentials —
  it binds to an `Unconfigured*` stand-in whose every call throws
  `NotConfiguredException` naming the missing variables. Failing at
  resolution broke every controller that constructor-injects a contract
  (Shops' domain pages 500ed on a server whose `.env` has no registrar
  keys). Asking for the concrete class (`app(Namecheap::class)`) still
  fails at once.

## 1.25.1 — 2026-09-05

- mailcow: `deleteAlias()` looks the alias's numeric id up first — mailcow's
  delete/alias wants the id, not the address (Shops found this live on
  2026-08-30); `addDomain()` checks for the domain before adding, since
  add/domain on an existing domain is a "danger" reply rather than a no-op;
  `dnsRecords()` now includes a DMARC record; new `Mail::deleteDomain()`.

## 1.25.0 — 2026-09-05

- **Infrastructure clients** (`Talivio\Sdk\Infra`): the domain registrar,
  DNS, hosting and mail integrations that Shops and Contentio each carried
  their own copy of now ship from the SDK behind four contracts —
  `Registrar` (Namecheap default, Openprovider alternate), `Dns` (Cloudflare),
  `Host` (Ploi) and `Mail` (mailcow). `BulkAvailability` is the optional
  batch-search extension of `Registrar`. Configuration lives under
  `talivio.infra` with the env names Contentio already used; Shops's spellings
  are read as fallbacks.
- Ploi supports both ways a product attaches customer domains to its site —
  `PLOI_ATTACH_MODE=tenant` (Shops) or `alias` (Contentio) — and gains
  site-level operations (`listSites`, `createSite`, `deleteSite`,
  `requestSiteCertificate`, `siteCertificateIssued`) for talivio.com's
  hosting ops page.
- `Registrar::register()` accepts an empty nameserver list (registrar defaults)
  for the register-then-create-zone order Cloudflare forces.
- In-memory fakes for every contract under `Talivio\Sdk\Infra\Testing`.
- The package now has its own test suite (Orchestra Testbench, every HTTP call
  faked) and a Pint configuration: `composer test`, `composer lint`.
