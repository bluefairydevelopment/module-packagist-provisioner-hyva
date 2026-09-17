<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * This package is sold. Until 1.1.1 it shipped `LICENSE.md` containing the
 * **Open Software License v3.0** — an OSI-approved open source licence that
 * grants a worldwide, royalty-free, sublicensable right to reproduce, modify and
 * redistribute — while `composer.json` declared `"license": ["proprietary"]`.
 * The two statements contradicted each other and the open one was the one in the
 * merchant's `vendor/` directory.
 *
 * Almost certainly inherited by copying Magento's own licence file, which is
 * OSL-3.0. Nothing here is a legal opinion; this test asserts only that the two
 * declarations agree and that the shipped text is the house instrument.
 *
 * **Estate measurement, 2026-09-11, 35 BlueFairy packages:**
 *
 *     8   ship a correct proprietary notice          (Printify x5, Slack Chat x3)
 *
 * This package was one of the 10. M2MAP-484.
 *    10   declare proprietary, ship OSL-3.0          (this package was one)
 *     3   declare proprietary, ship the GNU GPL      (cart-products x3)
 *     3   declare themselves open source in composer.json
 *    12   ship no licence file at all                (module-contact-success-graph-ql was one)
 *
 * **Not one test in the estate opened a licence file.** That is the whole reason
 * 27 of 35 could drift: phpcs and phpstan do not read `.md` or `.txt`, and a
 * missing licence file breaks no build. So this check exists, and every assertion
 * below is exercised against a specimen it plants itself — an assertion of
 * absence over prose is the shape most likely to be vacuous.
 */
class TheLicenceAMerchantReceivesTest extends TestCase
{
    /**
     * Grant language from the licences actually found in this estate. Matching
     * any of these means the shipped text gives the merchant rights the sale does
     * not.
     */
    private const OPEN_SOURCE_GRANTS = [
        'Open Software License',
        'GNU GENERAL PUBLIC LICENSE',
        'Academic Free License',
        'royalty-free, non-exclusive, sublicensable license',
    ];

    private const HOUSE_MARKERS = [
        'BLUE FAIRY DEVELOPMENT LLC',
        'END USER LICENSE AGREEMENT',
        'It is licensed, not sold.',
    ];

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function licencePath(): string
    {
        $found = glob(self::packageRoot() . '/LICEN[CS]E*') ?: [];
        self::assertCount(
            1,
            $found,
            'expected exactly one licence file in the package root, found: '
            . (implode(', ', array_map('basename', $found)) ?: 'none')
        );

        return $found[0];
    }

    public function testAProprietaryPackageShipsALicenceFile(): void
    {
        $path = self::licencePath();
        self::assertFileExists($path);
        self::assertGreaterThan(
            1000,
            (int) filesize($path),
            'the licence file is too short to be terms — a stub is not a licence'
        );
    }

    public function testTheComposerDeclarationAndTheShippedTextAgree(): void
    {
        $composer = json_decode((string) file_get_contents(self::packageRoot() . '/composer.json'), true);
        self::assertIsArray($composer);

        $declared = (array) ($composer['license'] ?? []);
        self::assertSame(
            ['proprietary'],
            $declared,
            'composer.json no longer declares this package proprietary; if that is deliberate the '
            . 'shipped licence text has to change with it, and this test is where you say so'
        );

        $text = (string) file_get_contents(self::licencePath());
        foreach (self::OPEN_SOURCE_GRANTS as $grant) {
            self::assertStringNotContainsStringIgnoringCase(
                $grant,
                $text,
                sprintf(
                    'the shipped licence contains "%s" while composer.json says proprietary. '
                    . 'A merchant reading vendor/ is told they may redistribute this.',
                    $grant
                )
            );
        }
    }

    public function testTheShippedTextIsTheHouseInstrument(): void
    {
        $text = (string) file_get_contents(self::licencePath());
        foreach (self::HOUSE_MARKERS as $marker) {
            self::assertStringContainsString(
                $marker,
                $text,
                sprintf('the licence does not carry "%s", so it is not the house EULA', $marker)
            );
        }
    }

    public function testTheLicenceNamesThisProductAndNotAnother(): void
    {
        $text = (string) file_get_contents(self::licencePath());

        self::assertStringContainsString(
            'Packagist Provisioner for Hyvä for Adobe Commerce',
            $text,
            'the licence does not name this product'
        );

        // THIS LICENCE IS A DERIVATION, AND A HALF-FINISHED ONE READS AS CORRECT.
        //
        // It was derived from module-jira's LICENSE.txt, which was itself derived
        // from the Printify EULA — four hunks each time: the product name, the
        // version, the Software definition's package list, and the third-party
        // service plus the trademark line. Every substantive clause is
        // byte-identical, which is the point of a derivation and also the hazard:
        // a copy that kept the SOURCE product's name is a licence for a different
        // product shipped inside this one, and nothing about it looks wrong.
        foreach (['Jira', 'Atlassian', 'Printify', 'Contact Success', 'ContactSuccess'] as $sourceProduct) {
            self::assertStringNotContainsString(
                $sourceProduct,
                $text,
                sprintf(
                    'the licence still names %s — the derivation did not finish, and every '
                    . 'substantive clause looks right regardless',
                    $sourceProduct
                )
            );
        }

        self::assertStringContainsString(
            'BlueFairy_PackagistProvisionerHyva',
            $text,
            'the Software definition does not list this package'
        );

        // This package is a single module. The Jira instrument it came from lists
        // three, so a derivation that kept that sentence would promise a merchant
        // two packages they did not buy and are not receiving.
        self::assertStringNotContainsString(
            'comprising the packages',
            $text,
            'the Software definition is still written for a multi-package suite; this '
            . 'package ships one module'
        );
    }

    /**
     * The controls. Each assertion above is an assertion about prose, and prose
     * assertions are the ones that quietly cannot fail — so each predicate is
     * run here against a specimen chosen to make it fire.
     */
    public function testEachPredicateFiresOnAPlantedSpecimen(): void
    {
        $osl = 'Open Software License ("OSL") v. 3.0' . "\n"
            . 'Licensor grants You a worldwide, royalty-free, non-exclusive, sublicensable license';

        $hits = 0;
        foreach (self::OPEN_SOURCE_GRANTS as $grant) {
            if (stripos($osl, $grant) !== false) {
                $hits++;
            }
        }
        self::assertSame(
            2,
            $hits,
            'the open-source-grant predicates do not match the exact OSL-3.0 header this package '
            . 'shipped until 1.1.1 — so their absence from the real file proves nothing'
        );

        $gpl = 'GNU GENERAL PUBLIC LICENSE Version 3';
        self::assertTrue(
            stripos($gpl, self::OPEN_SOURCE_GRANTS[1]) !== false,
            'the GPL predicate does not match a GPL header, and three packages in this estate ship one'
        );

        // And the house markers must be absent from an open licence, or
        // testTheShippedTextIsTheHouseInstrument would pass on OSL-3.0 too.
        foreach (self::HOUSE_MARKERS as $marker) {
            self::assertStringNotContainsString(
                $marker,
                $osl,
                sprintf('"%s" appears in OSL-3.0 text, so it does not discriminate', $marker)
            );
        }
    }

    /**
     * THE LICENCE CARRIES A HARD-CODED VERSION NUMBER, AND NOTHING WAS COMPARING IT.
     *
     * `LICENSE.txt` line 5 reads "Version X.Y.Z". composer.json has no version key here -
     * Packagist takes the version from the git tag - so that line is the only version string
     * a merchant receives in their vendor/ directory, and it is maintained by hand.
     *
     * At 1.1.2 all three Jira packages still said "Version 1.1.1", three commits and one
     * release after that stopped being true. A hard-coded version does not go stale loudly:
     * it stays plausible, it is in the one file nobody re-reads, and it is the file that says
     * what the merchant bought.
     *
     * The comparand is the newest `## X.Y.Z` heading in CHANGELOG.md, because that is the
     * other place the release number is written by hand and the two must agree. It is
     * deliberately NOT derived from `git describe`: a tag does not exist yet at the moment
     * this test needs to pass, so a gate keyed on one could never be green before a release.
     */
    public function testTheLicenceVersionMatchesTheNewestChangelogEntry(): void
    {
        $changelog = self::packageRoot() . '/CHANGELOG.md';
        self::assertFileExists($changelog, 'no CHANGELOG.md to compare the licence version against');

        self::assertSame(
            1,
            preg_match('/^## \[?(\d+\.\d+\.\d+)\]?/m', $changelogText = (string) file_get_contents($changelog), $newest),
            'CHANGELOG.md has no `## X.Y.Z` heading, so there is nothing to compare the licence '
            . 'version with and this check would pass over an empty search space'
        );

        $licence = (string) file_get_contents(self::packageRoot() . '/LICENSE.txt');
        self::assertSame(
            1,
            preg_match('/^Version (\d+\.\d+\.\d+)\s*$/m', $licence, $stated),
            'LICENSE.txt has no "Version X.Y.Z" line. It had one at 1.1.1; if the line has been '
            . 'renamed this assertion is now guarding nothing.'
        );

        // Keep-a-Changelog: an `## [Unreleased]` block with content above the newest
        // numbered heading means the tree is between releases, and the licence names the
        // version this tree will BECOME - one release ahead of the newest heading, never
        // equal to it (that would license unreleased changes under a shipped version's
        // number) and never behind. With no Unreleased block the two must agree exactly.
        $betweenReleases = self::hasUnreleasedEntries($changelogText);

        if ($betweenReleases) {
            self::assertSame(
                1,
                version_compare($stated[1], $newest[1]),
                sprintf(
                    'CHANGELOG.md has unreleased entries above %s, so LICENSE.txt must name the NEXT '
                    . 'version; it says %s. Bump it with the changelog heading at release, not after.',
                    $newest[1],
                    $stated[1]
                )
            );
            return;
        }

        self::assertSame(
            $newest[1],
            $stated[1],
            sprintf(
                'LICENSE.txt says Version %s and the newest CHANGELOG.md entry is %s. That line is '
                . 'the only version string a merchant receives, because composer.json carries no '
                . 'version key and Packagist reads the git tag.',
                $stated[1],
                $newest[1]
            )
        );
    }

    /**
     * The control for the rule above: text manufactured for each branch must route
     * to that branch, or an Unreleased detector that never fires would silently
     * demand equality for ever - or one that always fires would never demand it.
     */
    public function testTheBetweenReleasesDetectorSeesBothStates(): void
    {
        $between = "# Changelog\n\n## [Unreleased]\n\n### Fixed\n- something\n\n## [1.1.0] - 2026-09-09\n";
        $released = "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-09-09\n";
        $noBlock = "# Changelog\n\n## [1.1.0] - 2026-09-09\n";
        self::assertTrue(self::hasUnreleasedEntries($between), 'an Unreleased block WITH content was not detected');
        self::assertFalse(self::hasUnreleasedEntries($released), 'an EMPTY Unreleased block counted as between releases');
        self::assertFalse(self::hasUnreleasedEntries($noBlock), 'no Unreleased block counted as between releases');
    }

    /**
     * True when `## [Unreleased]` exists and the text between it and the next `## `
     * heading contains anything but whitespace. (A regex with a lookahead was tried
     * first and its trailing \S happily matched the NEXT heading's own `#` - the
     * control above caught it.)
     */
    private static function hasUnreleasedEntries(string $changelog): bool
    {
        $start = strpos($changelog, "## [Unreleased]");
        if ($start === false) {
            return false;
        }
        $bodyStart = $start + strlen("## [Unreleased]");
        $next      = strpos($changelog, "\n## ", $bodyStart);
        $body      = $next === false ? substr($changelog, $bodyStart) : substr($changelog, $bodyStart, $next - $bodyStart);

        return trim($body) !== '';
    }
}
