<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * M2MAP-632. Every credentials email says "View your token in My Licences", the
 * Verify controller redirects there, and the dashboard's own "Verify to view
 * credentials" link goes there - and through 1.1.0 this theme's My Licences page
 * showed no token at all. A customer re-entered their password and got a table.
 *
 * The rule: the page's template renders the released token (masked, with Reveal)
 * the same way the base package's Luma row does. The control: the same predicate
 * on the guest-lookup template, which must NOT carry a customer-account token.
 */
class TheLicencesPageShowsTheTokenTheEmailsPromiseTest extends TestCase
{
    private static function template(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/view/frontend/templates/' . $rel;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function testMyLicencesRendersTheReleasedTokenMaskedWithReveal(): void
    {
        $licences = self::template('account/licences.phtml');
        self::assertStringContainsString("['decrypted_token']", $licences, 'My Licences does not read the released token, so the emails send the customer to a page with nothing on it');
        self::assertStringContainsString('tokenVisible', $licences, 'the token is not masked behind a Reveal');
        self::assertStringContainsString("['repo_url']", $licences, 'the repository URL is not shown beside the token');
    }

    public function testTheControlTemplateDoesNotRenderAnAccountToken(): void
    {
        $verify = self::template('account/verify.phtml');
        self::assertStringNotContainsString("['decrypted_token']", $verify, 'CONTROL: the verify page renders a token before the password is entered');
    }

    /**
     * The words the emails use must name a page that has the token. Both email
     * templates live in the base package; they are read through the sibling
     * checkout or composer's vendor tree, and the test is skipped only if neither
     * exists - with the reason stated, so a skip is visible.
     */
    public function testTheEmailsSendTheCustomerToAPageThatHasTheToken(): void
    {
        $base = self::basePackage();
        if ($base === null) {
            self::markTestSkipped('base package not found beside this checkout or under MAGENTO_ROOT/vendor; cannot read its email templates');
        }
        foreach (['credentials_link_only.html', 'credentials_full.html'] as $tpl) {
            $email = (string) file_get_contents($base . '/view/frontend/email/' . $tpl);
            if (str_contains($email, 'customer/account/licences')) {
                self::assertStringContainsString("['decrypted_token']", self::template('account/licences.phtml'), "{$tpl} sends the customer to My Licences, which renders no token on this theme");
            }
        }
    }

    private static function basePackage(): ?string
    {
        $root = dirname(__DIR__, 3);
        foreach ([dirname($root) . '/module-packagist-provisioner', (getenv('MAGENTO_ROOT') ?: '') . '/vendor/bluefairy/module-packagist-provisioner'] as $c) {
            if (is_dir($c . '/view/frontend/email')) {
                return $c;
            }
        }
        return null;
    }
}
