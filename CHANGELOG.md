# Changelog

Releases are git tags on this repository (`vX.Y.Z`); products pin them through
Composer. History before 1.25.0 is in the commit log, where each release commit
carries its version in parentheses.

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
