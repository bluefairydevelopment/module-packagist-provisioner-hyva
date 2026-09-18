<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * An image nothing points at is where stale content and personal data go
 * unnoticed (M2MAP-259). Every image in docs/images must be referenced by a
 * shipped document, and every reference must resolve.
 */
class EveryShippedImageIsReadTest extends TestCase
{
    public function testEveryImageIsReferencedAndEveryReferenceResolves(): void
    {
        $root = dirname(__DIR__, 3);
        $onDisk = array_map('basename', glob($root . '/docs/images/*.png') ?: []);
        self::assertNotEmpty($onDisk, 'no images on disk - empty search space');

        $referenced = [];
        foreach (['FEATURES.md', 'README.md'] as $doc) {
            preg_match_all('/!\[[^\]]*\]\(docs\/images\/([^)]+)\)/', (string) file_get_contents($root . '/' . $doc), $m);
            foreach ($m[1] as $name) {
                $referenced[] = $name;
                self::assertFileExists($root . '/docs/images/' . $name, $doc . ' references an image that is not on disk: ' . $name);
            }
        }
        self::assertNotEmpty($referenced, 'no document references any image - empty search space');

        sort($onDisk);
        $referenced = array_values(array_unique($referenced));
        sort($referenced);
        self::assertSame($onDisk, $referenced, 'an image ships that no document points at, or vice versa');
    }
}
