# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
