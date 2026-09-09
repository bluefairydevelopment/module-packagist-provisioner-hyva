# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed
- **The shadow `1.0.0` tag** (M2MAP-256). This repository carried **both**
  `1.0.0` (`3e66f22`) and `v1.0.0` (`16de872`), tagged six days apart. Composer
  normalises the two spellings to the same version, so two different trees
  claimed to be the same release. Private Packagist resolved it in favour of
  `v1.0.0` — established by **asking Packagist** rather than reading the
  repository, its dist being `.../1.0.0.0/16de872….zip` — so the shadow tag had
  shipped nothing to anybody and could only mislead whoever read `git tag`
  next.

  Deleted local and remote on 2026-09-08 with Jennifer's explicit permission;
  removing a published ref from a repository feeding a live registry is
  outward-facing and waited for that. Because the dist already pointed at
  `v1.0.0`'s commit, the deletion **cannot change what any customer resolves**,
  and both commits were ancestors of HEAD, so nothing was orphaned. The restore
  command is preserved in `NoTwoTagsClaimOneVersionTest`'s docblock.

  `EXPECTED_DUPLICATES` is now **empty** and the guard is strict: any new
  collision fails it.

  **The fix created a vacuous green, and it is covered.** With one tag left the
  collision branch can no longer be entered by real data, so the real check
  would pass identically whether the detector worked or did nothing.
  `testTheDetectorFindsACollision` drives **the same `collisions()` method**
  with a known-colliding list. Proven: stubbing `collisions()` to return `[]`
  leaves the real check green and fails only the control.

- **`hyva_default.xml` is gone; its only directive was a no-op** (M2MAP-292). It
  removed a nav block called `customer-account-link-license-extensions`,
  described as a duplicate added by `Blue_LicenseDelivery`. **Nothing has ever
  declared that name** — the only occurrence anywhere in the Magento install was
  the line removing it, confirmed with a control (a real nav block name resolves
  to two files). `Blue_LicenseDelivery` does not exist in this estate.

  It arrived already wrong in `feat: initial release 1.0.0` and was later
  carefully *moved* to `hyva_default` with fourteen lines of reasoning about
  which handle Hyvä mirrors — all of it correct, all of it about a directive
  that did nothing.

  The base package's account-navigation link is
  `customer-account-link-packagist-licences`, a different name, so this removal
  never touched it and the navigation route was never at risk.

### Added
- **`EveryLayoutRemovalNamesSomethingRealTest`** — every `remove="true"` must
  name a block something declares, or be written down with the module named and
  how its presence was confirmed.

### Changed
- **Both view models now call the base package's `TokenReleaseService`**
  (M2MAP-269). The ACTIVE-only rule, the null-token check and the
  degrade-to-empty-string on a decryption failure were implemented inline here,
  twice, and twice more in the base package. Nothing forced the four to agree,
  so one theme could release a token the other correctly withheld.

  The step-up verdict is still this package's own decision and is passed in as
  an argument — the service deliberately requires it and supplies no default.

  **`TokenDecryptionIsGatedTest` had to be retargeted, and its floor is what
  caught that.** Its predicate was "the ViewModel source contains `->decrypt(`".
  Extracting the rule took the last such call out of this package, so the
  predicate matched nothing and the gate assertion became **vacuously true** —
  it would have passed against a companion showing every token to everybody. It
  now matches `->release(` as well, and adds the assertion that mattered: a
  literal `true` passed as the authorisation verdict is a failure, because that
  is precisely how someone makes this test green while removing the gate — the
  constructor argument and the `isVerified()` mention both survive it.

### Fixed
- **The dashboard block now carries the link to My Licences that README
  promises** (M2MAP-282). README.md has always described this block as
  "listing all active licences with status badges and a link through to the
  full My Licences page", and it never had one. M2MAP-260 added a link — but
  **only on the branch where the customer has not re-authenticated**, so the
  documented link existed in exactly the state where the customer was going to
  be redirected anyway, and was absent in the state the README describes.

  It matters beyond tidiness: **My Licences is where Renew lives**, and the
  dashboard is the page a customer lands on after signing in. With no link
  between them the only routes to the renewal control were the account
  navigation entry and typing the URL.

  The link is now rendered **unconditionally, outside the per-licence loop**, so
  it is the block's own link rather than one licence's prompt — including for
  the empty case, since a customer whose licence has expired off this list is
  exactly the one who needs the page that sells a renewal.

  **And the block was named after a page that does not exist.** Its title said
  "My Extensions"; the page title, the account-navigation entry and every email
  the base package sends say "My Licences" (M2MAP-268). Renamed here and in
  README.md.

  `Test/Unit/Package/DashboardBlockReachesTheLicencesPageTest.php` asserts the
  link's POSITION, not merely its presence — a link that only ever appears
  inside the loop is conditional on a licence and on that licence's state, and
  so cannot be the block's link to the page. Mutation-proven: moving the link
  back inside the loop fails exactly the position test; restoring the old title
  fails exactly the naming test.

  **Checked while here, and it holds:** the ticket warned that
  `hyva_default.xml` removes a nav link as a duplicate, so the navigation route
  needed re-checking. It removes `customer-account-link-license-extensions`; the
  base package's link is `customer-account-link-packagist-licences`. Different
  names, so the navigation entry survives. **That removal is a no-op** — no
  package has ever declared the name it targets, since 1.0.0 — and is filed
  separately rather than deleted here.

### Security
- **The Packagist token is no longer shown without password re-entry**
  (M2MAP-260). The base package refuses to release a token until the customer
  re-authenticates — `Controller/Account/Licences.php` redirects unless
  `StepUpSession::isVerified()`. This companion showed the same decrypted token
  with **no gate at all**, on two pages the base package places no block on:

  - the customer account **dashboard** (`hyva_customer_account_index.xml` →
    `LicenceListViewModel`), and
  - the **order view** (`hyva_sales_order_view.xml` → `OrderLicenceViewModel`).

  So one product enforced two different trust boundaries for one value
  depending only on which theme the merchant had chosen — and the companion our
  own README recommends for a Hyvä store was the ungated one. Anyone holding a
  live customer session could read the plaintext credential.

  **It was worse than "a Reveal button with no gate".** Both templates printed
  the plaintext into the HTML and used Alpine solely to toggle its *visibility*
  (`x-text="tokenVisible ? '<token>' : '●●●●'"`). Reveal was never a server
  round trip, so the token was in view-source whether or not anyone pressed it.

  The gate is therefore applied **before decryption, not before display**: both
  view models now take `StepUpSession` and never produce the plaintext at all
  unless the customer has verified. The licence is still listed — the gate hides
  the credential, not the fact that the customer owns the extension — and the
  templates now link to the gated licences page instead of rendering an empty
  panel.

  Not changed: the base package's `customer_account_licences` handle, which was
  already gated and is fed by the base's own `LicencesViewModel`.

  Verified by running the product, not only by the suite. On the rig, before the
  cure, `/customer/account/` carried `LOCAL-RIG-TOKEN-0001` for a customer who
  had not re-entered their password; after it, the token is absent before
  verification and present after. Mutation: neutering the gate in both view
  models turned exactly the two `testAnUnverifiedCustomerIsNeverHandedAToken`
  cases red and nothing else.

  **A trap worth recording, because it nearly signed this off.** The first
  version of the walkthrough assertion was
  `expect(html).not.toContain('LOCAL-RIG-TOKEN-0001')` — and it **passed against
  the leaking code**. Magento's `Escaper::escapeJs()` rewrites every
  non-alphanumeric character as `\uXXXX`, so the token reaches the HTML as
  `LOCAL\u002DRIG\u002DTOKEN\u002D0001` and the assertion of absence could
  never have failed. Only the positive control beneath it — "and the token IS
  there after verifying" — went red and exposed the blind check. The spec now
  decodes the escapes before asserting.


### Changed
- **The Hyvä requirement is `^1.1.15` instead of `*`** (M2MAP-252). The floor is
  measured, not guessed — a guessed floor refuses installs that work, which is
  worse than none.

  What this package actually depends on, surface by surface:

  - **`hyva_config_generate_before`** — the event the Tailwind registration
    observer listens on. It is registered to `Hyva\Theme\Observer\RegisterModuleForHyvaConfig`
    (`magento2-theme-module/src/etc/frontend/events.xml:26`), which is the exact
    observer this package's own mirrors, down to the
    `extensions[] = ['src' => substr($path, strlen(BP) + 1)]` contract. That
    observer is described by name in the **1.1.15** release notes — *"Register
    the Hyva_Theme module to be included in the tailwind purge config"*,
    2022-06-13, issue #170. **This is the floor.** Below it the event never
    fires, the observer never runs, and the module's Tailwind classes are purged
    — an unstyled page, with nothing logged.
  - **The `hyva_` layout handle convention** — predates the floor. Version
    **1.1.8** (2021-09-24) carries a *bugfix* extending the prefix to handles
    added with `<update>`, so the base convention, which is all this package
    needs since the nav cleanup moved to `hyva_default.xml`, is older still.
  - **`hyva.getFormKey()`** — needs no floor here, and could not be given one
    anyway. It is provided by **`magento2-default-theme`**, a different package
    that this one does not require and should not: a merchant on a
    Hyvä-derived theme need not have it. The guest-lookup template already
    handles its absence at runtime —
    `(typeof hyva !== 'undefined' && hyva.getFormKey) ? hyva.getFormKey() : '<?= … ?>'`
    — falling back to the view model's session form key. **A runtime feature
    check is the right answer to an unestablished version dependency; a pin is
    not.**

  Bounded at `<2.0` rather than left open: Composer warns on an unbound
  constraint, no Hyvä 2.x exists, and asserting that one would work is exactly
  the guess this ticket exists to avoid. Verified with Composer's own semver —
  1.5.2 and 1.1.15 satisfy it, **1.1.14 does not**, and neither does 2.0.0.
  `composer validate` is clean.

### Added
- **The in-package method-resolution check, scoped to both namespaces**
  (M2MAP-238) — `Test/Unit/Package/InPackageMethodCallsResolveTest.php`.

  Same check as the base package's, for the same shipped defect: a method called
  on a service that does not have it parses fine, installs fine, and raises
  `Error` — which `catch (\Exception)` does not catch — the first time a shopper
  reaches the line. Here it asserts across **both** `PackagistProvisionerHyva\`
  and `PackagistProvisioner\`, because three of this package's four classes
  inject a base service and a base method that is renamed or never existed is a
  break neither package's own suite would otherwise see.

  Denominators: 4 classes reflected, 9 property calls read, 4 of them on
  in-package types — each with a floor, so an empty sweep fails rather than
  passes. **Control:** a call to a method that does not exist on the *base*
  `GuestLookupService` was named by file, line and type, and the suite returned
  to green when it was removed. Suite is now **28 tests, exit 0**.

- **Gates, and the tests to run through them** (M2MAP-240). This package shipped
  with none: no `phpunit.xml.dist`, no `phpstan.neon.dist`, and `phpcs` could not
  even be pointed at it. Every claim ever made about it had been made through a
  browser.

  27 unit tests across all three view models and the Hyvä config observer.
  Gates now: **phpunit 27 tests exit 0**, **phpstan level 5 — 0 errors**,
  **phpcs — 0 errors, 0 warnings across 19 files**.

  Each gate was made to fail on purpose before being trusted, and each returned
  the specific finding expected:

  | gate | planted defect | result |
  | :--- | :--- | :--- |
  | phpunit | observer assigns `extensions` instead of appending | 1 failure — `testExistingRegistrationsArePreserved` |
  | phpstan | call to an undefined method on `FormKey` | 1 error — `method.notFound` |
  | phpcs | an `eval()` | 2 errors — "use of function eval() is forbidden" |

  All three returned to green on restore.

  The test bootstrap differs from the base package's in one way worth knowing:
  every class here names classes from `BlueFairy\PackagistProvisioner`, and
  composer maps that to the **installed** package rather than the sibling
  checkout. A sibling source tree therefore wins when one is present, and which
  tree was used is recorded in `BFD_BASE_PACKAGE_SOURCE` — otherwise a green here
  can be a green against a base package nobody has looked at.

### Fixed
- **`OrderLicenceViewModel` injected an `OrderRepositoryInterface` it never
  used.** Found by phpstan on its first ever run against this package
  (`property.onlyWritten`). Removed — it was a dependency the DI compiler had to
  resolve on every instantiation of the order-view block, for nothing. Note this
  changes a constructor signature, so `generated/` must be cleared, not just
  `cache:flush`.

- Two auto-fixable indentation warnings in `order/credentials.phtml` and
  `account/extensions.phtml`, also first surfaced by a gate that had never run.

### Fixed
- **The account-navigation cleanup depended on a Hyvä observer this package does
  not require.** Moved from `hyva_customer_account.xml` to `hyva_default.xml`.

  Hyvä mirrors handles with a `hyva_` prefix in two places: `AddLayoutHandles`
  (on `layout_load_before`) copies the `addHandle`-registered page handles, and
  `AddLayoutHandlesUpdates` (on `load_file_layout_updates_xml_after`) copies
  every `<update handle="X"/>` node. **`customer_account` is not a page handle** —
  nothing in `vendor/magento` calls `addHandle` for it; it is reached only
  through `<update handle="customer_account"/>`. So `hyva_customer_account`
  exists only by virtue of the *second* observer.

  It does work on the Hyvä installed here (1.5.2) — probed with a control, both
  applied. But `composer.json` requires `hyva-themes/magento2-theme-module` at
  `"*"`, so this package installs against versions predating that observer, and
  on one of those the file is inert: the duplicate "My Extensions" link returns
  with nothing anywhere to indicate it.

  `default` is always in `getHandles()`, so `hyva_default` is mirrored by the
  *first* observer and works on every Hyvä that supports the prefix convention
  at all. A `referenceBlock … remove` for a block that is not on the page is a
  no-op, so applying it site-wide costs nothing. Probed both directions before
  relying on it: the handle applies on the Hyvä store view and does **not** apply
  on the Luma one.

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
