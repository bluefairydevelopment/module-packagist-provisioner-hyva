<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * Hyvä's Tailwind build scans the THEME. A module's utility classes reach the
 * compiled stylesheet only if the module's own `view/frontend/tailwind/module.css`
 * declares an `@source` glob over its templates.
 *
 * module-jira-hyva shipped that file with **no `@source` at all** - 1,466 bytes of
 * comment, including a comment explaining that the file is what brings the classes
 * in. `npx hyva-sources` emitted an `@import` for it, exactly as the comment said,
 * and the `@import` imported a comment.
 *
 * Measured on hyvatest 2026-09-11 against the SERVED stylesheet: 39 of 110 distinct
 * class tokens across the two templates had no rule. After adding one line: 0 of
 * 110, with the same sweep still returning 39 against the pre-cure stylesheet. In
 * the browser the required asterisks were black rather than red, the Submit Ticket
 * button had no padding, and the field spacing was gone - with no console error and
 * no 404, on a page every other gate passed.
 *
 * Estate denominator at the time: 7 Hyvä companions ship a
 * `view/frontend/tailwind/module.css`; 6 declared an `@source`, this one among them -
 * and NOTHING pinned it (M2MAP-609, ported from module-jira-hyva on 2026-09-17).
 *
 * Nothing else in this package's gates can see this. phpcs and phpstan do not read
 * CSS; phpunit was green across it; the storefront returns 200 with the right
 * markup either way. So the check has to exist, and it has to be able to fail -
 * every assertion below is written against a specimen this test plants itself.
 */
class TailwindScansThisPackagesTemplatesTest extends TestCase
{
    private const SOURCE_FILE = '/view/frontend/tailwind/module.css';

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function moduleCss(): string
    {
        $path = self::packageRoot() . self::SOURCE_FILE;
        self::assertFileExists($path, 'the Tailwind registration file is missing entirely');
        $css = file_get_contents($path);
        self::assertIsString($css, 'the Tailwind registration file is unreadable');

        return $css;
    }

    /**
     * Strip block comments. The defect was a file whose ONLY mention of `@source`
     * was inside a comment describing what `@source` does, so a plain substring
     * search over the raw file would have passed on the broken state.
     */
    private static function directives(string $css): string
    {
        // The opener must be `/**`, a doc comment. The obvious `!/\*.*?\*/!s` EATS
        // THE GLOB: `../templates/**/*.phtml` contains `/*` ... `*/`, so a general
        // block-comment strip rewrites it to `../templates*.phtml` and the test
        // then reports the package's own templates as uncovered. It did exactly
        // that on the first run - a red from the instrument, dressed as a red from
        // the subject. testTheStripperDoesNotEatAGlobstarPath is the control.
        // M2MAP-609, porting to a package whose comment opens with `/*` not `/**`: strip
        // either opener, but only when it is followed by whitespace - `/**/` inside a
        // globstar path is followed by `/`, and survives.
        return (string) preg_replace('!/\*\*?(?=\s)[\s\S]*?\*/!', '', $css);
    }

    public function testTheRegistrationFileDeclaresAtLeastOneSourceDirective(): void
    {
        $directives = self::directives(self::moduleCss());

        self::assertMatchesRegularExpression(
            '/@source\b/',
            $directives,
            'view/frontend/tailwind/module.css declares no @source outside its comments, so '
            . 'Hyvä imports the file and scans none of this package\'s templates'
        );
    }

    public function testTheSourceGlobReachesEveryTemplateThisPackageShips(): void
    {
        $directives = self::directives(self::moduleCss());

        preg_match_all('/@source\s+"([^"]+)"/', $directives, $m);
        $globs = $m[1];
        self::assertNotEmpty($globs, 'no quoted @source glob to check');

        $templates = glob(self::packageRoot() . '/view/frontend/templates/*/*.phtml') ?: [];
        // A floor, not an exact count: the point is that an empty search space
        // cannot pass. Five templates ship in this package (order/, packagist/,
        // account/ x3); the floor sits below, and if the count ever drops to
        // zero the glob below would be trivially satisfied.
        self::assertGreaterThanOrEqual(
            3,
            count($templates),
            'fewer templates found than this package ships - the search space is wrong'
        );

        $base = self::packageRoot() . '/view/frontend/tailwind/';
        foreach ($templates as $template) {
            $covered = false;
            foreach ($globs as $glob) {
                foreach (glob($base . $glob) ?: [] as $hit) {
                    if (realpath($hit) === realpath($template)) {
                        $covered = true;
                        break 2;
                    }
                }
            }
            self::assertTrue(
                $covered,
                sprintf(
                    '%s is not reached by any @source glob (%s), so every utility used only there '
                    . 'is absent from the compiled stylesheet',
                    basename($template),
                    implode(', ', $globs)
                )
            );
        }
    }

    /**
     * The control. A glob is a claim about a search space, and a claim about a
     * search space is worth nothing until it has been seen to exclude something.
     */
    public function testTheGlobCheckRejectsATemplateOutsideIt(): void
    {
        $directives = self::directives(self::moduleCss());
        preg_match_all('/@source\s+"([^"]+)"/', $directives, $m);
        $base = self::packageRoot() . '/view/frontend/tailwind/';

        $planted = self::packageRoot() . '/view/frontend/templates/../layout';
        $outside = realpath($planted . '/hyva_customer_account_licences.xml');
        self::assertIsString($outside, 'the specimen this control needs does not exist');

        $reached = false;
        foreach ($m[1] as $glob) {
            foreach (glob($base . $glob) ?: [] as $hit) {
                if (realpath($hit) === $outside) {
                    $reached = true;
                }
            }
        }

        self::assertFalse(
            $reached,
            'the @source glob matches a layout XML file, so it is broader than intended and '
            . 'this test cannot distinguish a covered template from an uncovered one'
        );
    }

    /**
     * The comment-stripping control: prove the stripper removes a planted
     * `@source` inside a comment, so the first test above cannot pass on prose.
     */
    public function testACommentedOutSourceDoesNotCountAsADeclaration(): void
    {
        $specimen = "/*\n * See the @source directive.\n */\n";
        self::assertDoesNotMatchRegularExpression(
            '/@source\b/',
            self::directives($specimen),
            'the comment stripper leaves @source mentions inside comments, which is exactly how '
            . 'the original defect read as cured'
        );

        $specimen .= '@source "../templates/**/*.phtml";' . "\n";
        self::assertMatchesRegularExpression(
            '/@source\b/',
            self::directives($specimen),
            'the comment stripper removed a real directive'
        );
    }

    /**
     * The control for the control. A globstar path contains `/*` and `*​/`, so a
     * naive block-comment strip silently truncates it - and the truncated glob
     * still matches SOMETHING, which is why the damage reads as a finding about
     * the package rather than about the stripper.
     */
    public function testTheStripperDoesNotEatAGlobstarPath(): void
    {
        $specimen = '@source "../templates/**/*.phtml";';

        self::assertStringContainsString(
            '../templates/**/*.phtml',
            self::directives($specimen),
            'the comment stripper mangled a globstar path'
        );

        // And the naive form, kept here so the difference is demonstrated rather
        // than asserted: this is what the first version of this test did.
        self::assertSame(
            '@source "../templates*.phtml";',
            (string) preg_replace('!/\*.*?\*/!s', '', $specimen),
            'the naive stripper no longer truncates the glob, so this control has '
            . 'stopped demonstrating anything and should be re-aimed or removed'
        );
    }
}
