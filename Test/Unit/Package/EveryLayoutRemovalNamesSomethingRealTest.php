<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * M2MAP-292. A LAYOUT REMOVAL FOR A NAME NOTHING DECLARES IS A NO-OP THAT READS
 * LIKE A DECISION.
 *
 * Two of these shipped in every release since 1.0.0, one in each package, both
 * claiming to remove duplicates added by a module called Blue_LicenseDelivery:
 *
 *   customer-account-link-license-extensions   (companion, hyva_default.xml)
 *   customer.account.verify                    (base, customer_account_verify.xml)
 *
 * Neither name has ever been declared by anything. Blue_LicenseDelivery does
 * not exist in this estate - searched across the whole Magento install with a
 * working control - and its only mentions anywhere are the places these two
 * packages talk about it. One of them carried fourteen lines of careful
 * reasoning about WHERE to place a directive that does nothing.
 *
 * The cost is not the dead line. It is that the comment tells the next reader a
 * conflict was found and handled, so nobody looks for the duplicate again - and
 * if one ever WAS real, it is still there.
 *
 * A DEFENSIVE REMOVAL AGAINST AN UNINSTALLED MODULE IS LEGITIMATE, which is why
 * this test does not simply demand that every removed name be declared. It
 * demands that the removal be either resolvable or WRITTEN DOWN with a reason.
 * The list below is empty; both entries that would have been in it were
 * fictions.
 */
class EveryLayoutRemovalNamesSomethingRealTest extends TestCase
{
    /**
     * Block names this package removes that are declared by a module we do not
     * install. EVERY ENTRY NEEDS A REASON naming the module and how its
     * presence was confirmed - "if present" is not a reason, it is a guess.
     *
     * @var array<string, string>
     */
    private const DEFENSIVE_REMOVALS = [];

    public function testEveryBlockThisPackageRemovesIsOneSomethingDeclares(): void
    {
        $root = dirname(__DIR__, 3);

        $declared = $this->declaredBlockNames($root);

        // The control. If the declaration scan cannot see a name this package
        // certainly declares, every "nothing declares this" verdict is noise.
        $this->assertNotEmpty(
            $declared,
            'no block declarations were found at all, so this test cannot tell a real name from a '
            . 'fictional one'
        );

        $unresolved = [];
        $examined   = 0;

        foreach ((array) glob($root . '/view/frontend/layout/*.xml') as $file) {
            $contents = (string) file_get_contents((string) $file);
            preg_match_all('/<referenceBlock\s+name="([^"]+)"\s+remove="true"/', $contents, $matches);

            foreach ($matches[1] as $name) {
                $examined++;
                if (isset(self::DEFENSIVE_REMOVALS[$name])) {
                    continue;
                }
                if (in_array($name, $declared, true)) {
                    continue;
                }
                $unresolved[] = sprintf('%s (in %s)', $name, basename((string) $file));
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            sprintf(
                "%d layout removal(s) name a block nothing declares. Either the name is wrong - in "
                . "which case the duplicate it was meant to remove is STILL THERE - or the removal "
                . "is defensive against a module we do not install, in which case add it to "
                . "DEFENSIVE_REMOVALS with the module named and how its presence was confirmed. "
                . "Examined %d removals against %d declared names:\n  %s",
                count($unresolved),
                $examined,
                count($declared),
                implode("\n  ", $unresolved)
            )
        );
    }

    /**
     * Block and container names declared anywhere this test can see: this
     * package's own layout, and - when MAGENTO_ROOT is set - the installed
     * vendor tree, which is where a genuine third-party conflict would live.
     *
     * @param string $root
     * @return string[]
     */
    private function declaredBlockNames(string $root): array
    {
        $names = [];

        $roots = [$root . '/view'];
        $magento = getenv('MAGENTO_ROOT') ?: '';
        if ($magento !== '') {
            $roots[] = $magento . '/vendor/magento';
        }

        foreach ($roots as $scanRoot) {
            if (!is_dir($scanRoot)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scanRoot, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $path = (string) $file;
                if (!str_ends_with($path, '.xml') || !str_contains($path, '/layout/')) {
                    continue;
                }

                $contents = (string) file_get_contents($path);
                preg_match_all('/<(?:block|container|referenceBlock)[^>]*\sname="([^"]+)"/', $contents, $m);
                foreach ($m[1] as $name) {
                    if (!str_contains($contents, 'name="' . $name . '" remove="true"')) {
                        $names[$name] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }
}
