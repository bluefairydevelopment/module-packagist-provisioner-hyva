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

    public function testTheHookRunsEveryGateOnATagPush(): void
    {
        $hook = (string) file_get_contents(self::root() . '/.githooks/pre-push');
        self::assertStringContainsString('refs/tags/', $hook, 'the hook does not distinguish a tag push');
        foreach (['phpunit', 'phpcs', 'phpstan', 'composer validate'] as $gate) {
            self::assertStringContainsString($gate, $hook, "the hook no longer runs {$gate} on a tag push");
        }
        self::assertStringContainsString('MAGENTO_ROOT', $hook, 'the hook does not require a Magento root, so phpstan would run against nothing');
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
