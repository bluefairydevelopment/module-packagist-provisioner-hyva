# BlueFairy_PackagistProvisionerHyva

Hyvä theme frontend for `BlueFairy_PackagistProvisioner`. Adds a My Extensions block to the customer account dashboard and a full My Licences page with step-up password verification and token credential display.

---

## Requirements

- Magento 2.4.4+
- PHP 8.1+
- [Hyvä Theme](https://hyva.io) 1.3+
- [`bluefairy/module-packagist-provisioner`](https://github.com/bluefairydevelopment/module-packagist-provisioner) ^1.0

---

## Installation

### 1. Add the BlueFairy repository

```bash
composer config repositories.bluefairy-packagist composer https://bluefairy.repo.packagist.com
```

Or add it directly in `composer.json`:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://bluefairy.repo.packagist.com"
        }
    ]
}
```

### 2. Add authentication credentials

Add your token to `auth.json` (in your Magento root or `~/.composer/auth.json`):

```json
{
    "http-basic": {
        "bluefairy.repo.packagist.com": {
            "username": "token",
            "password": "<your-token-from-my-account>"
        }
    }
}
```

Or via Composer CLI:

```bash
composer config --auth http-basic.bluefairy.repo.packagist.com token <your-token>
```

### 3. Install

Install the core module first if you haven't already:

```bash
composer require bluefairy/module-packagist-provisioner
```

Then install this module:

```bash
composer require bluefairy/module-packagist-provisioner-hyva
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## Features

### My Extensions — Dashboard Block

A summary block appears on the customer account dashboard (below the address panel) listing all active licences with status badges and a link through to the full My Licences page.

### My Licences Page

Accessible at `/customer/account/licences`. Shows a table of all licences with:

| Column | Details |
|---|---|
| Package | Composer package name |
| Order | Order increment ID |
| Status | Colour-coded badge (Active / Pending / Expired / Revoked / Failed) |
| Expires | Formatted date with days-remaining countdown |
| Actions | View Credentials link (opens `repo_url`), Renew button (≤ 60 days remaining) |

### Step-Up Verification

Before accessing the My Licences page the customer must re-enter their password. The verified state is held in the customer session for the duration of the browser session — no additional prompts until they log out.

### Renewal

A **Renew** button appears on any active licence with 60 days or fewer remaining. Clicking it submits a POST to `/customer/account/renew` which reissues a new Packagist token and marks the old one revoked.

---

## Troubleshooting

**My Extensions block not appearing on dashboard**
- Confirm `BlueFairy_PackagistProvisionerHyva` is enabled: `bin/magento module:status BlueFairy_PackagistProvisionerHyva`
- Flush layout cache: `bin/magento cache:flush layout block_html full_page`

**My Licences shows "You have no licences yet"**
- Check that `BlueFairy_PackagistProvisioner` has provisioned licences for the logged-in customer's email in **BlueFairy → Packagist Licences**.

**Verify page does not redirect after password entry**
- Ensure there is no conflicting `customer_account_verify` layout handle from another module.
- Check browser console for JavaScript errors that might be intercepting the form submit.

---

## About Blue Fairy Development

Blue Fairy Development specialises in Magento 2 and Hyvä theme development, building performant, maintainable e-commerce solutions for merchants who need more than out-of-the-box Magento provides.

- **Website:** [bluefairydevelopment.com](https://bluefairydevelopment.com)
- **Support & enquiries:** [support@bluefairydevelopment.com](mailto:support@bluefairydevelopment.com)
