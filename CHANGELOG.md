# Changelog

Releases are git tags on this repository (`vX.Y.Z`); products pin them through
Composer. History before 1.25.0 is in the commit log, where each release commit
carries its version in parentheses.

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
