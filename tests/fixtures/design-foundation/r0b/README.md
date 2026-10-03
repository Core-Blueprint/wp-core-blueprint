# Design Foundation R0b golden fixtures

R0b freezes the pre-Foundation document-rendering baseline before any `CoreBlueprint\Core\Design\` production code is introduced.

## What is frozen

`baseline.json` pins the verified source commits and relevant blob identities for:

- Base Maintenance Reports;
- Certificates' current fixed A4 Designer/render path;
- Commerce Essentials' current invoice/credit-note render path;
- the synthetic Shipping Label proof definition reserved for R3b.

The fixture records semantic/render observables and ownership boundaries. It deliberately does **not** copy consumer implementation code into Base.

## Why there is no golden PDF hash

The current PDF binary is a terminal renderer artifact. Dompdf/PHP/environment metadata can change bytes without changing the consumer-visible document contract, and Maintenance Reports intentionally renders its PDF on demand without permanent PDF storage.

R0b therefore compares:

1. pinned source/render provenance;
2. document semantics that must survive migration;
3. renderer/security policy;
4. consumer-owned domain boundaries.

Later migration gates may add deterministic normalized render comparisons or terminal artifact checks where a consumer owns immutable files. R0b does not pretend that a PDF byte hash proves semantic parity.

## Legacy differences are not invariants

The fixture explicitly records two locale behaviors that must **not** become Foundation contracts:

- Certificates currently derives the HTML language from `determine_locale()`;
- Commerce Essentials currently writes `lang="en"` while translated labels use the active WordPress translation context.

The new Document subsystem must receive an explicit consumer-owned render locale. Those entries are migration review points, not parity requirements.

## Shipping Label proof

`shipping-label-r3b-proof-definition` is intentionally synthetic. It proves later that an independent consumer can use the public extension boundary without importing Certificates or Commerce internals.

R0b does not choose paper dimensions, carrier semantics, barcode symbology, a production provider ID, or a public Design schema/API. Those decisions remain deferred until R3b has a concrete proof.

## Immutability rule

Do not silently rewrite this baseline to make a later migration pass. If the baseline itself is proven wrong, change it only through an explicit reviewed fixture-generation update with a documented reason.
