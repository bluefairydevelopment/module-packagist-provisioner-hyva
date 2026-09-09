<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * M2MAP-256. TWO TAGS CLAIMED VERSION 1.0.0 AT DIFFERENT COMMITS. CURED.
 *
 * Composer normalises "1.0.0" and "v1.0.0" to the same version, so two
 * different trees, tagged six days apart, both claimed to be 1.0.0. Private
 * Packagist resolved it in favour of v1.0.0 - established by ASKING PACKAGIST
 * rather than reading the repository:
 *
 *     composer show <this package> --all
 *     dist: .../1.0.0.0/16de872ee3c5343e58ef6ddd6192056810decc8b.zip
 *
 * so the shadow tag shipped nothing to anybody and could only mislead whoever
 * read `git tag` next.
 *
 * THE SHADOW TAG WAS DELETED ON 2026-09-08, local and remote, with Jennifer's
 * explicit permission - the deletion is an outward-facing act on a repository
 * feeding a live registry, so it waited for that. Because the dist above
 * already pointed at v1.0.0's commit, removing the other ref could not change
 * what any customer resolves. Both commits were ancestors of HEAD, so nothing
 * was orphaned; to restore:
 *
 *     git tag 1.0.0 3e66f2277fe276d16da991a786616aa154c5dcf2 && git push origin 1.0.0
 *
 * EXPECTED_DUPLICATES is now empty and the test is strict: any new collision
 * fails it.
 *
 * WHY THERE IS A SYNTHETIC CONTROL. With one tag left in the repository the
 * collision branch can no longer be entered by real data, so a green here would
 * otherwise be vacuous - it would look identical whether the detector worked or
 * did nothing at all. testTheDetectorFindsACollision feeds a known-colliding
 * list through THE SAME collisions() method the real assertion uses, so the
 * pass below is backed by a demonstration that the instrument fires.
 */
class NoTwoTagsClaimOneVersionTest extends TestCase
{
    /**
     * Known collisions still awaiting a deletion. Empty since 2026-09-08.
     * Each entry would be "normalised version" => what to do about it.
     *
     * @var array<string, string>
     */
    private const EXPECTED_DUPLICATES = [];

    public function testNoTwoTagsNormaliseToTheSameComposerVersion(): void
    {
        $tags = $this->tags();

        if ($tags === []) {
            self::markTestSkipped('no git tags are visible from here; nothing to compare');
        }

        // The control: normalisation must actually collapse the two spellings,
        // or a collision could never be detected in the first place.
        self::assertSame(
            $this->normalise('v9.9.9'),
            $this->normalise('9.9.9'),
            'the normaliser does not collapse the v prefix, so it cannot see the collision this '
            . 'test exists for'
        );

        $unexpected = $this->collisions($tags);

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
     * The positive control for the assertion above.
     *
     * The repository now holds a single tag, so the real list can no longer
     * produce a collision and cannot demonstrate that the detector works. This
     * drives the SAME collisions() method with a list that must collide.
     */
    public function testTheDetectorFindsACollision(): void
    {
        $found = $this->collisions(['1.0.0', 'v1.0.0', '2.0.0']);

        self::assertCount(
            1,
            $found,
            'collisions() did not report the deliberate "1.0.0" / "v1.0.0" collision, so a green '
            . 'from testNoTwoTagsNormaliseToTheSameComposerVersion means nothing'
        );
        self::assertStringContainsString('1.0.0 claimed by', $found[0]);
        self::assertStringContainsString('v1.0.0', $found[0]);
    }

    /**
     * Every normalised version claimed by more than one tag, minus the
     * allowlist. Shared by the real check and its control on purpose.
     *
     * @param string[] $tags
     *
     * @return string[]
     */
    private function collisions(array $tags): array
    {
        $byVersion = [];
        foreach ($tags as $tag) {
            $byVersion[$this->normalise($tag)][] = $tag;
        }

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

        return $unexpected;
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
