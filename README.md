# BlueFairy_PackagistProvisionerHyva

Hyvä theme frontend for `BlueFairy_PackagistProvisioner`. Adds a **My Licences** block to the customer account dashboard and a full My Licences page, both behind step-up password verification, both showing the masked token with Reveal/Copy.

---

## Requirements

- Magento 2.4.4+
- PHP 8.1 – 8.5

The PHP constraint is enumerated (`~8.1.0 || … || ~8.5.0`) rather than `>=8.1`,
which admitted PHP 9 and everything after it (M2MAP-280). The range is broad and
safe because Composer installs only the intersection with your Magento release's
own PHP requirement — 2.4.9 requires `~8.3.0||~8.4.0||~8.5.0`, so an incoherent
pair cannot be selected whatever this package says.
- [Hyvä Theme](https://hyva.io) 1.3+
- [`bluefairy/module-packagist-provisioner`](https://github.com/bluefairydevelopment/module-packagist-provisioner) ^1.1

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

### My Licences — Dashboard Block

A summary block appears on the customer account dashboard (below the address panel) listing the customer's licences of every status with status badges - the token and repository URL are shown for ACTIVE ones once verified - and a link through to the full My Licences page.

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

Before accessing the My Licences page the customer must re-enter their password. The verified state is held in the customer session for **15 minutes** (`StepUpSession::WINDOW_SECONDS`), after which the password is asked for again.

### Renewal

Renewal is a repurchase: the customer buys the package again and the new order extends the licence from whichever is later, its current expiry or the order date (the base package's `ProvisioningService::handleRenewal()`). An active licence with 60 days or fewer remaining shows an *Expiring soon - purchase again to extend* prompt on My Licences. There is no free renewal control; the one that shipped through 1.1.0 issued a full-term licence with no order behind it (M2MAP-619).

---

## Troubleshooting

**My Licences block not appearing on dashboard**
- Confirm `BlueFairy_PackagistProvisionerHyva` is enabled: `bin/magento module:status BlueFairy_PackagistProvisionerHyva`
- Flush layout cache: `bin/magento cache:flush layout block_html full_page`

**My Licences shows "You have no licences yet"**
- Check that `BlueFairy_PackagistProvisioner` has licences attached to the logged-in customer's **account** (customer id, not email) in **BlueFairy → Packagist Licences**. A guest purchase is attached with the grid's *Link to Customer* action.

**Verify page does not redirect after password entry**
- Ensure there is no conflicting `customer_account_verify` layout handle from another module.
- Check browser console for JavaScript errors that might be intercepting the form submit.

---

## About Blue Fairy Development

Blue Fairy Development specialises in Magento 2 and Hyvä theme development, building performant, maintainable e-commerce solutions for merchants who need more than out-of-the-box Magento provides.

- **Website:** [bluefairydevelopment.com](https://bluefairydevelopment.com)
- **Support & enquiries:** [support@bluefairydevelopment.com](mailto:support@bluefairydevelopment.com)
