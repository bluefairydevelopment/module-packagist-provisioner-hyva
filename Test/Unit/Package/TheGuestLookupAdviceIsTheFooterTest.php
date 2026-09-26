<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * M2MAP-655, the companion's half. FEATURES.md must point merchants to the footer and to a check
 * on the rendered storefront, never to a support/contact block - the estate's own store proved
 * such a block can render nowhere. The base package holds its README to the same rule.
 */
class TheGuestLookupAdviceIsTheFooterTest extends TestCase
{
    /**
     * @return string[]
     */
    private static function problems(string $features): array
    {
        $problems = [];
        if (!preg_match('/guestlookup/', $features)) {
            $problems[] = 'no guest-lookup section found';
        }
        if (preg_match('/natural home|support or contact block/i', $features)) {
            $problems[] = 'recommends a support/contact block';
        }
        if (!preg_match('/\bfooter\b[\s\S]{0,200}verify it on the rendered/i', $features)) {
            $problems[] = 'does not advise the footer plus a rendered-page check';
        }

        return $problems;
    }

    public function testFeaturesAdvisesTheFooterAndARenderedPageCheck(): void
    {
        $features = (string) file_get_contents(dirname(__DIR__, 3) . '/FEATURES.md');
        self::assertSame([], self::problems($features));
    }

    public function testTheCheckRejectsTheOldWording(): void
    {
        $old = "`/bluefairy/packagist/guestlookup` ... No navigation item, footer link or block in either package"
            . " points at it ... See the base package's README (M2MAP-653).";
        self::assertSame(
            ['does not advise the footer plus a rendered-page check'],
            self::problems($old),
            'CONTROL: the 1.2.1 wording (which mentions "footer" only as something that does not exist) passes'
        );
    }
}
