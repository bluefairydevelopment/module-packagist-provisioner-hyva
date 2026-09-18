<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * A TEST CANNOT READ A PICTURE. It can refuse to ship bytes nobody has attested to.
 *
 * These images go on bluefairydevelopment.com as this product's gallery, read out of the
 * INSTALLED module's own `docs/` by `Blue_ProductSetup`'s product patch. So an image added,
 * changed or removed without a human opening it is a change to what a buyer sees, made by
 * nobody in particular.
 *
 * Pinning by SHA-256 in `docs/images/REVIEWED.md` makes that a deliberate act: any change to
 * the bytes turns this red until the table is updated, and updating the table is the point at
 * which somebody has to look at the file.
 *
 * IT IS NOT THEATRE, AND THIS PACKAGE HAS THE RECEIPT. Two real problems in the first capture
 * run were caught by opening the files - internal fixture text in frame, and a content check
 * passing over an empty list - and neither was visible to the capture script's own assertions.
 */
class ShippedImagesAreReviewedTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function imagesDir(): string
    {
        return self::root() . '/docs/images';
    }

    /**
     * @return array<string, array{bytes:int, sha:string}> file => attestation
     */
    private static function attested(): array
    {
        $record = self::imagesDir() . '/REVIEWED.md';
        self::assertFileExists($record, 'docs/images/REVIEWED.md is missing, so nothing is attested');

        $rows = [];
        foreach (explode("\n", (string) file_get_contents($record)) as $line) {
            if (!preg_match('/^\|\s*`([^`]+\.(?:png|jpe?g|gif|webp|svg))`\s*\|\s*(\d+)\s*\|\s*`([0-9a-f]{8,})…?`/', trim($line), $m)) {
                continue;
            }
            $rows[$m[1]] = ['bytes' => (int) $m[2], 'sha' => $m[3]];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function onDisk(): array
    {
        if (!is_dir(self::imagesDir())) {
            return [];
        }
        $files = [];
        foreach ((array) scandir(self::imagesDir()) as $entry) {
            // Every raster/vector format, not only PNG: a staged leak.jpg shipped past the
            // PNG-only filter with both gates green (frozen-round finding, 2026-09-17).
            if (is_string($entry) && preg_match('/\.(?:png|jpe?g|gif|webp|svg)$/i', $entry)) {
                $files[] = $entry;
            }
        }
        sort($files);

        return $files;
    }

    public function testEveryShippedImageIsPinnedAndEveryPinnedImageShips(): void
    {
        $attested = self::attested();
        $onDisk = self::onDisk();

        // CONTROL. An add/remove comparison between two lists is satisfied by both being empty
        // - a moved docs/ directory, a regex that stopped matching the table, a scandir on the
        // wrong path. Both sides are shown to be populated before they are compared.
        self::assertNotEmpty($onDisk, 'no image in docs/images, so this test would compare two empty sets');
        self::assertNotEmpty($attested, 'REVIEWED.md attests no image, so this test would compare two empty sets');

        $unpinned = array_values(array_diff($onDisk, array_keys($attested)));
        self::assertSame([], $unpinned, sprintf(
            'image(s) in docs/images with no entry in REVIEWED.md: %s. These would ship to a '
            . 'merchant, and onto the product gallery, without anybody having opened them.',
            implode(', ', $unpinned)
        ));

        $missing = array_values(array_diff(array_keys($attested), $onDisk));
        self::assertSame([], $missing, sprintf(
            'REVIEWED.md attests image(s) that are not on disk: %s. The product patch on the '
            . 'selling site copies these out of the installed module and copy() THROWS on a '
            . 'missing file - at install time on the live site, not at build time.',
            implode(', ', $missing)
        ));
    }

    public function testNoAttestedImageHasChangedSinceItWasReviewed(): void
    {
        $attested = self::attested();
        self::assertNotEmpty($attested, 'CONTROL: nothing attested, so the loop below asserts nothing');

        foreach ($attested as $file => $claim) {
            $path = self::imagesDir() . '/' . $file;
            self::assertFileExists($path, sprintf('%s is attested and absent', $file));

            $bytes = (int) filesize($path);
            self::assertSame($claim['bytes'], $bytes, sprintf(
                '%s is %d bytes and REVIEWED.md says %d. Somebody changed a shipped image '
                . 'without re-reviewing it.',
                $file,
                $bytes,
                $claim['bytes']
            ));

            $sha = (string) hash_file('sha256', $path);
            self::assertStringStartsWith($claim['sha'], $sha, sprintf(
                '%s hashes %s… and REVIEWED.md attests %s…. Same size, different bytes - which '
                . 'is exactly the change a byte count alone cannot see.',
                $file,
                substr($sha, 0, 16),
                $claim['sha']
            ));
        }
    }
}
