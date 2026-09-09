<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * M2MAP-256. TWO TAGS CLAIMED VERSION 1.0.0 AT DIFFERENT COMMITS.
 *
 * Composer normalises "1.0.0" and "v1.0.0" to the same version, so two
 * different trees, tagged six days apart, both claimed to be 1.0.0. Private
 * Packagist resolved it in favour of v1.0.0 - verified by ASKING PACKAGIST
 * rather than reading the repository:
 *
 *     composer show bluefairy/module-packagist-provisioner --all
 *     dist: .../1.0.0.0/16de872ee3c5343e58ef6ddd6192056810decc8b.zip
 *
 * so the other tag shipped nothing to anybody and can only mislead whoever
 * reads `git tag` next.
 *
 * THE DELETION IS NOT MINE TO MAKE. Removing a published ref from a repository
 * that feeds a live package registry is an outward-facing action, and it was
 * refused by this session's permission layer - correctly. It is recorded in
 * EXPECTED_DUPLICATES below with the exact restore commands, and this test goes
 * red the moment a NEW collision appears, which is the part that was actually
 * missing.
 *
 * WHY THE EXEMPTION IS DATED AND NAMED rather than the test being deleted: an
 * allowlist entry that nobody has to clear is indistinguishable from no test.
 * When Jennifer removes the tags, this constant goes back to empty and the test
 * gets stricter by itself.
 */
class NoTwoTagsClaimOneVersionTest extends TestCase
{
    /**
     * Known collisions, pending a deletion only a human with publish rights can
     * make. Each entry is "normalised version" => what to do about it.
     *
     * @var array<string, string>
     */
    private const EXPECTED_DUPLICATES = [
        '1.0.0' => 'M2MAP-256: tags "1.0.0" (3e66f2277fe276d16da991a786616aa154c5dcf2) and "v1.0.0" (16de872ee3c5343e58ef6ddd6192056810decc8b). '
            . 'Packagist serves v1.0.0; the other shipped nothing. Delete with '
            . '`git tag -d 1.0.0 && git push origin :refs/tags/1.0.0`; restore with '
            . '`git tag 1.0.0 3e66f2277fe276d16da991a786616aa154c5dcf2 && git push origin 1.0.0`. Both commits are '
            . 'ancestors of HEAD, so the deletion orphans nothing.',
    ];

    public function testNoTwoTagsNormaliseToTheSameComposerVersion(): void
    {
        $tags = $this->tags();

        if ($tags === []) {
            self::markTestSkipped('no git tags are visible from here; nothing to compare');
        }

        $byVersion = [];
        foreach ($tags as $tag) {
            $byVersion[$this->normalise($tag)][] = $tag;
        }

        // The control: normalisation must actually collapse the two spellings,
        // or a collision could never be detected in the first place.
        self::assertSame(
            $this->normalise('v9.9.9'),
            $this->normalise('9.9.9'),
            'the normaliser does not collapse the v prefix, so it cannot see the collision this '
            . 'test exists for'
        );

        $unexpected = [];
        foreach ($byVersion as $version => $claiming) {
            if (count($claiming) < 2) {
                continue;
            }
            if (isset(self::EXPECTED_DUPLICATES[$version])) {
                continue;
            }
            $unexpected[] = sprintf('%s claimed by: %s', $version, implode(', ', $claiming));
        }

        self::assertSame(
            [],
            $unexpected,
            sprintf(
                "%d composer version(s) are claimed by more than one tag. Composer normalises them "
                . "to one version, so two different trees claim to be the same release and only one "
                . "of them ships. Examined %d tags:\n  %s",
                count($unexpected),
                count($tags),
                implode("\n  ", $unexpected)
            )
        );
    }

    /**
     * @return string[]
     */
    private function tags(): array
    {
        $root = dirname(__DIR__, 3);
        $output = [];
        $status = 0;

        exec(sprintf('git -C %s tag -l 2>/dev/null', escapeshellarg($root)), $output, $status);

        return $status === 0 ? array_values(array_filter(array_map('trim', $output))) : [];
    }

    private function normalise(string $tag): string
    {
        return ltrim(strtolower(trim($tag)), 'v');
    }
}
