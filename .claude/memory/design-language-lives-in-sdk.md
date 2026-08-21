---
name: design-language-lives-in-sdk
description: "Talivio's shared visual language (cut corners, drop-shadow, glow, standard footer) ships from talivio/sdk v1.12.0 — never copy it into a product"
metadata: 
  node_type: memory
  type: project
  originSessionId: 222e4c38-b65b-40e1-a365-0ddc407ca500
  modified: 2026-07-27T09:49:13.088Z
---

As of 2026-07-27 the Talivio visual language has a single source in the
`talivio/sdk` package (v1.12.0): `resources/css/talivio-design.css` plus
`<x-talivio::footer>` and `<x-talivio::logo>`. Products import the CSS from the
vendor path — the package deliberately does **not** publish it.

**Why:** copied layers diverge (the reason recorded in ADR-30 and ADR-33);
copying CSS into 20 products would repeat that divergence in what customers see.

**How to apply:** when applying the design language to any Talivio product
(vendio, rivo, shops, invonio, …), pull it from the SDK — do not paste the CSS
or the footer markup into the product. The product's `app.css` needs both the
`@import` of the package CSS and the `@source` line scanning
`vendor/talivio/sdk/resources/views` (without the scan, package classes never
reach the CSS — shipped broken on 2026-07-26). The written contract lives in
`talivio-ai/docs/tasarim-dili.md`.
