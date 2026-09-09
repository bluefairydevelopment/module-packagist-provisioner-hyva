<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * M2MAP-282. THE DASHBOARD BLOCK NEVER CARRIED THE LINK THE README PROMISES.
 *
 * README.md describes the block as "listing all active licences with status
 * badges and a link through to the full My Licences page". Before M2MAP-260 the
 * template had no anchor outside the Copy/Reveal buttons at all.
 *
 * M2MAP-260 then added one - but ONLY on the branch where the customer has not
 * re-authenticated. So the documented link existed in exactly the state where
 * the customer was going to be redirected anyway, and was absent in the state
 * the README describes. A per-licence link inside a conditional inside the loop
 * is not "a link through to the full page"; it is a prompt to verify.
 *
 * IT MATTERS BEYOND TIDINESS. My Licences is where Renew lives, and the
 * dashboard is the page a customer lands on after signing in. With no link
 * between them the only routes to the renewal control are the account
 * navigation entry and typing the URL.
 */
class DashboardBlockReachesTheLicencesPageTest extends TestCase
{
    private const TEMPLATE = 'view/frontend/templates/account/extensions.phtml';
    private const LICENCES_PATH = 'customer/account/licences';

    private string $packageRoot;
    private string $template;

    protected function setUp(): void
    {
        $this->packageRoot = dirname(__DIR__, 3);

        $path = $this->packageRoot . '/' . self::TEMPLATE;
        $this->assertFileExists($path, 'the dashboard block template is not where this test looks for it');
        $this->template = (string) file_get_contents($path);

        // Control. If the template ever stops rendering a per-licence loop the
        // structural assertion below loses its meaning, and it should say so
        // rather than quietly pass.
        $this->assertStringContainsString(
            'endforeach',
            $this->template,
            'the template no longer loops over licences, so "outside the loop" is not a '
            . 'meaningful position and this test needs rewriting'
        );
    }

    /**
     * The link must be in the BLOCK, not in a per-licence branch. Position is
     * the check: everything the loop emits is conditional on a licence and on
     * that licence's state, so a link that only ever appears inside the loop
     * cannot be the block's link to the page.
     */
    public function testTheLinkToMyLicencesIsRenderedOutsideThePerLicenceLoop(): void
    {
        $lastEndForeach = strrpos($this->template, 'endforeach');
        $this->assertNotFalse($lastEndForeach);

        $offsets = [];
        $offset = 0;
        while (($found = strpos($this->template, self::LICENCES_PATH, $offset)) !== false) {
            $offsets[] = $found;
            $offset = $found + 1;
        }

        $this->assertNotEmpty(
            $offsets,
            sprintf(
                '%s contains no link to %s at all. README.md describes this block as carrying '
                . 'one, and My Licences is the only route to the renewal control.',
                self::TEMPLATE,
                self::LICENCES_PATH
            )
        );

        $outside = array_filter($offsets, static fn (int $o): bool => $o > $lastEndForeach);

        $this->assertNotEmpty(
            $outside,
            sprintf(
                'every link to %s in %s sits INSIDE the per-licence loop, so it renders only for '
                . 'licences in a particular state - before this cure, only for a customer who has '
                . 'NOT re-authenticated. In the state the README describes, the customer sees their '
                . 'credentials and no route to the page where Renew lives. Link offsets: %s; the '
                . 'loop ends at %d.',
                self::LICENCES_PATH,
                self::TEMPLATE,
                implode(', ', $offsets),
                $lastEndForeach
            )
        );
    }

    /**
     * M2MAP-268's other half, in this package. The page, its account-navigation
     * entry and every email the base package sends call this "My Licences".
     * Nothing in either package is called "My Extensions", so a customer told
     * to look for it is being sent after a name that does not exist.
     */
    public function testTheBlockDoesNotNameAPageThatNoLongerExists(): void
    {
        preg_match_all('/\bMy [A-Z][A-Za-z]+/', $this->template, $matches);

        // The control: a negative assertion about page names is worthless if
        // the template names no pages at all.
        $this->assertNotEmpty(
            $matches[0],
            'no page name was found in the template, so the assertion below could not have failed'
        );

        $this->assertNotContains(
            'My Extensions',
            $matches[0],
            sprintf(
                '%s still calls the licences surface "My Extensions". The page title, the account '
                . 'navigation link and every email in the base package say "My Licences". Names '
                . 'found here: %s',
                self::TEMPLATE,
                implode(', ', array_unique($matches[0]))
            )
        );
    }

    /**
     * A referenceBlock remove for a name nothing declares is a no-op that reads
     * like a decision. Found while curing this ticket: hyva_default.xml removes
     * "customer-account-link-license-extensions" as a duplicate nav link, and
     * that name has never been declared by either package - the base package's
     * link is "customer-account-link-packagist-licences". Filed separately; this
     * test pins the fact so the two names cannot silently diverge again.
     */
    public function testEveryNavigationBlockThisPackageRemovesIsOneItCanName(): void
    {
        $removed = [];
        foreach ((array) glob($this->packageRoot . '/view/frontend/layout/*.xml') as $file) {
            preg_match_all(
                '/<referenceBlock\s+name="([^"]+)"\s+remove="true"/',
                (string) file_get_contents((string) $file),
                $matches
            );
            foreach ($matches[1] as $name) {
                $removed[$name] = basename((string) $file);
            }
        }

        if ($removed === []) {
            $this->addToAssertionCount(1);
            return;
        }

        foreach ($removed as $name => $file) {
            $this->assertMatchesRegularExpression(
                '/^customer-account-link-/',
                $name,
                sprintf('%s removes "%s"; this test only knows how to reason about nav links', $file, $name)
            );
        }

        $this->assertNotEmpty($removed, 'nothing examined');
    }
}
