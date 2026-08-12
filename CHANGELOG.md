# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-12

### Added
- Initial release: Hyvä theme frontend for `BlueFairy_PackagistProvisioner`
- My Extensions block on customer account dashboard (shows active licences with status badges)
- My Licences page with full table: package, order, status, expiry, credentials link
- Step-up verify password form (Tailwind/Hyvä, no Alpine.js dependency)
- Renewal button visible when licence is active and expiring within 60 days
- README with installation instructions for the Private Packagist Composer workflow
- Removes duplicate nav links from `Blue_LicenseDelivery` if present
