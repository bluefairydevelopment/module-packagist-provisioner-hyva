<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * M2MAP-594 / M2MAP-546. NOTHING RAN THIS PACKAGE'S GATES BEFORE A TAG.
 *
 * The pre-push hook that does is `.githooks/pre-push`. It used to be gitignored,
 * so "no hook" was the default state of every fresh clone, and it only ever
 * checked the CHANGELOG. This test holds the three facts that decide whether the
 * gate exists at all: the hook is TRACKED, it RUNS THE GATES on a tag push, and
 * THIS CLONE is wired to it. The last one is deliberately about the environment -
 * a hook that is tracked and correct and not configured is the same as no hook,
 * and the failure message is the one-line fix.
 *
 * The hook's own control (a planted red test refusing a tag push to a throwaway
 * bare remote) is recorded in the hook's header and in the CHANGELOG; a test
 * cannot push a tag, so that part stays a documented procedure.
 */
class TheReleaseGateIsArmedTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function git(string $args): string
    {
        $out = [];
        $status = 0;
        exec('cd ' . escapeshellarg(self::root()) . ' && git ' . $args . ' 2>&1', $out, $status);
        if ($status !== 0) {
            throw new RuntimeException("git {$args} exited {$status}: " . implode("\n", $out));
        }
        return implode("\n", $out);
    }

    public function testTheHookIsTrackedNotGitignored(): void
    {
        $tracked = self::git('ls-files -- .githooks/pre-push');
        self::assertSame(
            '.githooks/pre-push',
            trim($tracked),
            '.githooks/pre-push is not tracked. It was gitignored until 1.2.0, which is why a '
            . 'fresh clone had no release gate at all.'
        );
        self::assertTrue(is_executable(self::root() . '/.githooks/pre-push'), 'the hook is not executable, so git will not run it');
    }

    /**
     * OVER THE EXECUTABLE LINES ONLY. The first version of this test matched the
     * whole file, and the hook's own header comment names every gate - so a hook
     * with all four `gate` calls deleted stayed green (frozen-round finding,
     * 2026-09-17). Comment lines are stripped first; the control below proves
     * a comment-only mention no longer satisfies the predicate.
     */
    public function testTheHookRunsEveryGateOnATagPush(): void
    {
        $code = self::executableLines((string) file_get_contents(self::root() . '/.githooks/pre-push'));
        self::assertStringContainsString('refs/tags/', $code, 'the hook does not distinguish a tag push');
        foreach (['phpunit', 'phpcs', 'phpstan', 'composer validate'] as $gate) {
            self::assertMatchesRegularExpression(
                '/^\s*gate \S+ .*' . preg_quote($gate, '/') . '/m',
                $code,
                "the hook no longer runs {$gate} through gate() on a tag push"
            );
        }
        self::assertStringContainsString('MAGENTO_ROOT', $code, 'the hook does not require a Magento root, so phpstan would run against nothing');
    }

    public function testACommentNamingAGateDoesNotCountAsRunningIt(): void
    {
        $specimen = "#!/usr/bin/env bash\n# this hook runs phpunit, phpcs, phpstan and composer validate\n# on refs/tags/* pushes\nexit 0\n";
        $code = self::executableLines($specimen);
        self::assertStringNotContainsString('phpunit', $code, 'CONTROL: the comment stripper left a gate name from a comment');
        self::assertDoesNotMatchRegularExpression('/^\s*gate /m', $code);

        $real = "gate phpunit env MAGENTO_ROOT=x phpunit -c phpunit.xml.dist\n";
        self::assertMatchesRegularExpression('/^\s*gate \S+ .*phpunit/m', self::executableLines($real), 'CONTROL: a real gate line was not recognised');
    }

    /** Shell source with every comment line and every trailing comment removed. */
    private static function executableLines(string $shell): string
    {
        $kept = [];
        foreach (explode("\n", $shell) as $line) {
            $stripped = preg_replace('/^\s*#.*$/', '', $line);
            if (trim((string) $stripped) !== '') {
                $kept[] = $stripped;
            }
        }
        return implode("\n", $kept);
    }

    public function testTheHookDoesNotShipToMerchants(): void
    {
        $attributes = (string) file_get_contents(self::root() . '/.gitattributes');
        self::assertMatchesRegularExpression('/^\.githooks\/\s+export-ignore/m', $attributes, '.githooks/ is not export-ignored, so the release gate would ship inside the package');
    }

    public function testThisCloneIsWiredToTheHook(): void
    {
        $out = [];
        exec('cd ' . escapeshellarg(self::root()) . ' && git config core.hooksPath 2>/dev/null', $out);
        self::assertSame(
            '.githooks',
            trim(implode('', $out)),
            "This clone's core.hooksPath is not .githooks, so the tracked release gate is NOT "
            . "running for you and a tag push here is ungated. Fix, once:\n\n"
            . "    git config core.hooksPath .githooks\n"
        );
    }
}
