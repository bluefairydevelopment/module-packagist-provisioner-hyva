# Shipped images — reviewed record

Every image in `docs/images/` is pinned here by SHA-256. `Test/Unit/Package/ShippedImagesAreReviewedTest`
fails on any image added, changed or removed until this table is updated, which turns a silent
omission into a deliberate act. **A test cannot read a picture; it can refuse to ship bytes
nobody has attested to.** Ported from the base package (M2MAP-444) on 2026-09-18, when this package
first shipped images at all.

| File | Bytes | SHA-256 | Reviewed note |
|------|-------|---------|---------------|
| `dashboard-licences.png` | 46769 | `21b603ab7ace7a24…` | **Ships.** The My Licences block on the Hyvä account dashboard before step-up, cropped to the block (the account's contact card above it carries the test account's name and email and is not the subject). Captured 2026-09-17 on the 1.2.0 walkthrough (P13 frame); same bytes as the base package's `hyva-dashboard-licences.png`. Three fixture packages, *Verify to view credentials*, the *Manage licences* link. No PII. |
| `my-licences.png` | 175728 | `fdcf3ff2551b522d…` | **Ships.** My Account → My Licences on Hyvä, captured 2026-09-17 on the 1.2.0 walkthrough (P8 frame); same bytes as the base package's `my-licences.png`. Three fixture packages, Active/Active/Expired, the masked token with Reveal, the truncated repository value with Copy, the expiry notice on the two licences inside 60 days. Synthetic account; no name, email or token in frame - the dots are the mask, the URLs are `repo.packagist.com/bluefairy/local-N/` fixtures. |
| `guest-lookup.png` | 101321 | `e794b1d405b740f4…` | **Ships.** The guest credential-retrieval form on Hyvä, empty, placeholder text only. Captured 2026-09-17; same bytes as the base package's `guest-lookup.png`. No PII. |
| `guest-lookup-result.png` | 53622 | `017a9fb16611826a…` | **Ships.** A successful guest lookup on Hyvä (P9 frame, 2026-09-17); same bytes as the base package's. Order 000000004, the fixture package, its repository URL with Copy, the token masked with Reveal. **The email field is Gaussian-blurred** (a fixture address on a personal domain; 7,257 of 20,592 px changed, verified). Header and footer cropped. |

## Not here, and why

- The order-page block (§5 of FEATURES.md) has no capture: the test store's fixture orders are all
  guest orders, so no signed-in account owns one. It is described, not photographed, until a fixture
  order for the test account exists.
