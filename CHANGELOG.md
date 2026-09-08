# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **This package's layout applied on EVERY theme, not just Hyvä.** None of its
  six layout files carried the `hyva_` prefix, so Magento loaded them on a Luma
  store view as well — and a Luma shopper was served Alpine templates on a page
  with **no Alpine loaded at all**, with inputs carrying `x-model` and no `name`
  attribute, and a call to `hyva.getFormKey()` that is undefined outside Hyvä.
  The guest lookup could not have worked there under any circumstances.

  Hyvä adds `hyva_`-prefixed layout handles only when the current theme is a
  Hyvä theme (`Hyva\Theme\Observer\AddLayoutHandles`; its own integration test
  for the negative case is named `unchanged_if_not_hyva_theme`). Every layout
  file here is renamed accordingly:

  | was | now |
  | :--- | :--- |
  | `bluefairy_packagist_packagist_guestlookup.xml` | `hyva_bluefairy_packagist_packagist_guestlookup.xml` |
  | `customer_account.xml` | `hyva_customer_account.xml` |
  | `customer_account_index.xml` | `hyva_customer_account_index.xml` |
  | `customer_account_licences.xml` | `hyva_customer_account_licences.xml` |
  | `customer_account_verify.xml` | `hyva_customer_account_verify.xml` |
  | `sales_order_view.xml` | `hyva_sales_order_view.xml` |

  Measured on a rig carrying both themes: on the Luma store view the guest page
  went from the Hyvä template with 0 `name="order_number"` fields and a dead
  Alpine binding, to the base package's Luma template with a working form; the
  Hyvä store view is unchanged. This was only visible because a second theme was
  finally walked — a Hyvä-only rig cannot see it, and it had been true since the
  package's first release.

### Added
- **Hyvä templates for `/customer/account/licences` and
  `/customer/account/verify`** (M2MAP-242). This package supplied templates for
  the dashboard, the order view and the guest lookup, but nothing for those two
  — so a Hyvä shopper got the BASE package's Luma templates there. Worse than a
  markup mismatch: those templates are written in Tailwind classes, and the
  Hyvä config observer registers only this package as a Tailwind source, so the
  base module's utilities were purged from the compiled CSS and both pages
  rendered unstyled. Both are reachable from the account navigation, and the
  verify page guards the licences page for every customer.

  Verified: of the 85 class tokens in the two new templates, all 76 that are
  Tailwind utilities are present in the compiled `styles.css`; the 9 that are
  not are structural hooks (`block-content`, `form-verify-identity`,
  `password`, `required`) which are not utilities and are not expected to be.
  Control: three utilities no template uses (`bg-fuchsia-700`,
  `hover:bg-fuchsia-700`, `focus:ring-fuchsia-700`) are absent, so the check can
  report an absence.

  Credentials are deliberately NOT shown on the licences page. The
  masked-token-with-Reveal surface is My Extensions on the dashboard; repeating
  it on a second page would widen where a token can be read over a shoulder for
  no requirement in FEATURES.md.

### Fixed
- **The guest lookup form could never have succeeded, for three reasons.**
  It POSTed to `bluefairy/packagist/guestLookupPost`, a controller that had never
  been written — that endpoint now exists in the base package. Beyond that:

  - The form key was read as `$block->getData('form_key')`, an argument the block
    is never given in layout, so it was always `''` and every request would have
    failed CSRF. It now comes from `hyva.getFormKey()` at submit time, the way
    Hyvä's own templates do it: a key rendered into the HTML is baked at
    cache-write time and no longer matches the visitor's session.
  - `GuestLookupViewModel::lookup()` returned `['licence' => $licenceObject]`
    while the template reads `entry.package_name`, `entry.status`,
    `entry.repo_url` and `entry.decrypted_token`. The results would have rendered
    blank. The view model now delegates to `GuestLookupService` in the base
    package, which returns flat arrays keyed as the template reads them.
  - The Alpine handler awaited `resp.json()` with no `try`/`finally`, so any
    non-JSON response left `loading` true: the button stayed disabled on
    "Looking up…" and displayed no error at all. That is exactly how the 404
    presented for the entire life of the feature.

  Verified end to end on the rig: order number + email now returns the order's
  package, repository URL and token, and wrong email, unknown order, empty input
  and a forged form key each fail distinctly.

### Fixed
- **The guest credential-lookup page had never rendered this package's
  template.** Its layout file was named `bluefairy_packagist_guestlookup_index.xml`,
  but Magento builds a layout handle as `<route-id>_<controller>_<action>` — so
  the handle for this page is `bluefairy_packagist_packagist_guestlookup`.
  Nothing ever dispatched the old name, and the file was inert.

  What a Hyvä shopper actually saw was the base package's **Luma** template,
  which declares the correct handle. The page looked fine, which is why nobody
  noticed; the tell was the heading — "Retrieve Your Packagist Credentials"
  rather than "Retrieve Extension Credentials". Measured before and after:
  Hyvä heading 0 → 1, Luma heading 1 → 0, the Alpine form's `orderNumber`
  binding 0 → 3.

  It overrides the base block rather than adding a second one, which would have
  rendered two lookup forms on one page.

### Fixed
- The module was not registered with Hyvä's Tailwind build, so every utility
  class appearing ONLY in this module's templates was purged from the compiled
  theme CSS. Hyvä does not scan module directories: the theme declares explicit
  `@source` globs and `generated/hyva-source.css` lists only the modules named
  in `app/etc/hyva-themes.json`, which a module joins by observing
  `hyva_config_generate_before`. Measured on the local Hyvä theme, before and
  after: **10 utilities went from absent to present** in `web/css/styles.css` —
  `bg-blue-100`, `bg-green-100`, `bg-yellow-100`, `text-blue-600`,
  `text-blue-700`, `text-blue-800`, `text-green-600`, `text-green-800`,
  `text-yellow-800`, `w-24`. Those are the colour-coded licence status badges
  and the token column width, so the status colours were rendering as plain
  text on every Hyvä store this module has ever been installed on. There is no
  console error and no 404 when this happens; the class simply does nothing.

  **Installing the fix is not sufficient on its own.** The observer only puts
  the module into the generator's INPUT. Per install, after deploying:

  ```
  bin/magento cache:flush
  bin/magento hyva:config:generate
  cd <theme>/web/tailwind && npm ci && npm run build   # NOT build-prod
  bin/magento cache:flush
  ```

  The FIRST `cache:flush` is load-bearing and is the step most easily skipped.
  Event configuration is served from the `config` cache, and `hyva:config:generate`
  spawns its own object manager that reads it. Until that cache is flushed the
  new observer is invisible to the command, which then rewrites
  `hyva-themes.json` WITHOUT this module and still exits 0 — indistinguishable
  from the fix not working. `cache:clean config` was measured to be insufficient;
  `cache:flush` was sufficient.

### Changed
- `Test/` is now `export-ignore`d, so the distributed archive no longer carries
  the test estate into a merchant's `vendor/`. The tests stay in the repository
  and in the gates. The attribute lives at the package root because `git archive`
  reads `.gitattributes` from inside the tree it archives.

## [1.0.0] - 2026-08-12

### Changed
- Remove hardcoded version field from composer.json — version comes from git tag

### Added
- Initial release: Hyvä theme frontend for `BlueFairy_PackagistProvisioner`
- My Extensions block on customer account dashboard (shows active licences with status badges)
- My Licences page with full table: package, order, status, expiry, credentials link
- Step-up verify password form (Tailwind/Hyvä, no Alpine.js dependency)
- Renewal button visible when licence is active and expiring within 60 days
- README with installation instructions for the Private Packagist Composer workflow
- Removes duplicate nav links from `Blue_LicenseDelivery` if present
