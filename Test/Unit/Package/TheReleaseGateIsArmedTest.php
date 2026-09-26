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

    /**
     * M2MAP-656, RUN RATHER THAN READ. Each case builds a throwaway repository, then feeds the
     * hook the four fields git writes on its stdin. Refusals are checked by exit status and, for
     * the tag rule, by message; every refusal has an acceptance beside it, so a hook that refuses
     * everything cannot pass - and the original defect WAS a hook refusing everything.
     */
    public function testTheBranchRuleSeesEveryCommitOfAMultiCommitFirstPush(): void
    {
        $first = 'printf a > a.php && git add a.php && git commit -q -m a'
            . ' && printf x > CHANGELOG.md && git add CHANGELOG.md && git commit -q -m cl'
            . ' && printf b > b.php && git add b.php && git commit -q -m b';
        self::assertSame(0, self::runHook($first, 'refs/heads/main $(git rev-parse HEAD) refs/heads/main ' . self::Z40)[0],
            'a three-commit first push with CHANGELOG.md in the middle commit was refused (the M2MAP-656 defect)');

        $none = 'printf a > a.php && git add a.php && git commit -q -m a'
            . ' && printf b > b.php && git add b.php && git commit -q -m b'
            . ' && printf c > c.php && git add c.php && git commit -q -m c';
        self::assertSame(1, self::runHook($none, 'refs/heads/main $(git rev-parse HEAD) refs/heads/main ' . self::Z40)[0],
            'CONTROL: a three-commit first push with no CHANGELOG.md change was accepted');
    }

    public function testTheBranchRuleSeesACommitAndItsRevertOnAnExistingBranch(): void
    {
        $setup = 'printf base > a.php && git add a.php && git commit -q -m base && old=$(git rev-parse HEAD)'
            . ' && printf change >> a.php && git commit -q -am change && git revert --no-edit HEAD >/dev/null';
        self::assertSame(1, self::runHook($setup, 'refs/heads/main $(git rev-parse HEAD) refs/heads/main $old')[0],
            'a commit and its revert on an existing branch slipped past the changelog rule');
        self::assertSame(0, self::runHook($setup . ' && printf x > CHANGELOG.md && git add CHANGELOG.md && git commit -q -m cl',
            'refs/heads/main $(git rev-parse HEAD) refs/heads/main $old')[0], 'CONTROL: the same push with a changelog was not accepted');
    }

    /**
     * The fail-OPEN shape a per-commit "touched" test alone allows (review of 1.2.2): one commit
     * adds a changelog entry, a later one reverts just that, and the push lands with no changelog
     * change in its final tree. Refused on an existing branch and on a first push alike.
     */
    public function testAChangelogAddedAndRevertedInOnePushDoesNotCount(): void
    {
        $existing = 'printf base > a.php && printf v1 > CHANGELOG.md && git add -A && git commit -q -m base && old=$(git rev-parse HEAD)'
            . ' && printf feat > f.php && printf v2 > CHANGELOG.md && git add -A && git commit -q -m feature'
            . ' && printf v1 > CHANGELOG.md && git commit -q -am "revert changelog only"';
        self::assertSame(1, self::runHook($existing, 'refs/heads/main $(git rev-parse HEAD) refs/heads/main $old')[0],
            'an added-then-reverted changelog satisfied the rule on an existing branch');

        $first = 'printf a > a.php && printf x > CHANGELOG.md && git add -A && git commit -q -m a'
            . ' && git rm -q CHANGELOG.md && git commit -q -m "drop changelog"';
        self::assertSame(1, self::runHook($first, 'refs/heads/main $(git rev-parse HEAD) refs/heads/main ' . self::Z40)[0],
            'an added-then-deleted changelog satisfied the rule on a first push');
    }

    /**
     * The first-push BOUNDARY path, against a real remote with fetched refs (review round 2 of
     * 1.2.2): a new branch cut from an already-pushed trunk is compared with the trunk tip that
     * `git rev-list --boundary ... --not --remotes` finds. A changelog entry that is added then
     * reverted on the new branch is refused, a real one accepted, and one deleted outright is
     * refused because the pushed tree no longer carries a changelog.
     */
    public function testAFirstPushIsComparedWithTheRemoteBoundary(): void
    {
        $remote = 'git init -q --bare .git/test-remote && git remote add origin "$PWD/.git/test-remote"'
            . ' && printf v1 > CHANGELOG.md && printf a > a.php && git add -A && git commit -q -m base'
            . ' && git push -q origin main 2>/dev/null && git fetch -q origin && git checkout -q -b topic';

        $roundTrip = $remote . ' && printf v2 > CHANGELOG.md && printf f > f.php && git add -A && git commit -q -m f'
            . ' && printf v1 > CHANGELOG.md && git commit -q -am revert';
        self::assertSame(1, self::runHook($roundTrip,
            'refs/heads/topic $(git rev-parse HEAD) refs/heads/topic ' . self::Z40)[0],
            'a first push whose changelog matches the remote boundary was accepted');

        $real = $remote . ' && printf v2 > CHANGELOG.md && git commit -q -am entry';
        self::assertSame(0, self::runHook($real,
            'refs/heads/topic $(git rev-parse HEAD) refs/heads/topic ' . self::Z40)[0],
            'CONTROL: a first push with a real changelog change against the boundary was refused');

        $deleted = $remote . ' && git rm -q CHANGELOG.md && git commit -q -m drop';
        self::assertSame(1, self::runHook($deleted,
            'refs/heads/topic $(git rev-parse HEAD) refs/heads/topic ' . self::Z40)[0],
            'deleting the changelog satisfied the rule');
    }

    public function testAMergeCountsOnlyWhatItIntroduces(): void
    {
        // A changelog written while resolving the merge counts (--cc sees it; no flag would not).
        $inMerge = 'printf base > a.php && git add a.php && git commit -q -m base && old=$(git rev-parse HEAD)'
            . ' && git checkout -q -b side && printf s > s.php && git add s.php && git commit -q -m side'
            . ' && git checkout -q main && printf m > m.php && git add m.php && git commit -q -m main'
            . ' && (git merge -q --no-ff --no-commit side >/dev/null 2>&1 || true)'
            . ' && printf x > CHANGELOG.md && git add CHANGELOG.md && git commit -q -m merge';
        self::assertSame(0, self::runHook($inMerge, 'refs/heads/main $(git rev-parse HEAD) refs/heads/main $old')[0],
            'a changelog added in the merge commit itself was not seen');

        // An already-pushed changelog inherited through a merge of an older branch does not (-m would).
        $inherited = 'printf base > a.php && git add a.php && git commit -q -m base && git branch side'
            . ' && printf x > CHANGELOG.md && git add CHANGELOG.md && git commit -q -m cl && old=$(git rev-parse HEAD)'
            . ' && printf o > o.php && git add o.php && git commit -q -m other'
            . ' && git checkout -q side && printf f > f.php && git add f.php && git commit -q -m feature'
            . ' && git checkout -q main && git merge -q --no-ff -m merge side';
        self::assertSame(1, self::runHook($inherited, 'refs/heads/main $(git rev-parse HEAD) refs/heads/main $old')[0],
            'merging an old branch made an already-pushed changelog count for a push that changes none');
    }

    public function testATagIsGatedOnlyWhenItIsHeadAndTheTreeIsClean(): void
    {
        $two = 'printf one > a.txt && git add a.txt && git commit -q -m one && first=$(git rev-parse HEAD)'
            . ' && printf two >> a.txt && git commit -q -am two';

        [$status, $err] = self::runHook($two, 'refs/tags/v9.9.9 $first refs/tags/v9.9.9 ' . self::Z40);
        self::assertSame(1, $status);
        self::assertStringContainsString('points at', $err, 'a tag on an older commit was not refused as such');

        [$status, $err] = self::runHook($two . ' && printf x > untracked.txt', 'refs/tags/v9.9.9 $(git rev-parse HEAD) refs/tags/v9.9.9 ' . self::Z40);
        self::assertSame(1, $status);
        self::assertStringContainsString('working tree has uncommitted', $err, 'a dirty tree was not refused');

        [$status, $err] = self::runHook($two, 'refs/tags/v9.9.9 $(git rev-parse HEAD) refs/tags/v9.9.9 ' . self::Z40);
        self::assertSame(1, $status);
        self::assertStringContainsString('MAGENTO_ROOT', $err, 'CONTROL: a clean HEAD tag did not get past the HEAD and clean-tree checks');
    }

    public function testEachBranchOfAMultiBranchPushNeedsItsOwnChangelog(): void
    {
        // `git push origin a b` sends one stdin line per branch. The verdict was a pair of flags
        // OR'd across the push, so a's changelog let b land with none (review round 3 of 1.2.2).
        $setup = 'printf v1 > CHANGELOG.md && git add -A && git commit -q -m base && old=$(git rev-parse HEAD)'
            . ' && git checkout -q -b a && printf v2 > CHANGELOG.md && git commit -q -am entry && a=$(git rev-parse HEAD)'
            . ' && git checkout -q -b b $old && printf x > b.php && git add b.php && git commit -q -m code && b=$(git rev-parse HEAD)';
        $a = 'refs/heads/a $a refs/heads/a $old';
        $b = 'refs/heads/b $b refs/heads/b $old';

        foreach (['a then b' => $a . "\n" . $b, 'b then a' => $b . "\n" . $a] as $order => $lines) {
            [$status, $err] = self::runHook($setup, $lines);
            self::assertSame(1, $status, "a branch with no changelog rode along with one that has it ({$order})");
            self::assertStringContainsString('change: b', $err, "the refusal does not name branch b ({$order})");
        }
        self::assertSame(0, self::runHook($setup, $a)[0], 'CONTROL: branch a alone, with its changelog, was refused');
    }

    public function testEveryTagOfAMultiTagPushIsChecked(): void
    {
        // `git push --tags` carries several refs. The 1.2.1 hook kept only the LAST tag it read,
        // so an older commit's tag listed first went out ungated (M2MAP-672).
        $two = 'printf one > a.txt && git add a.txt && git commit -q -m one && first=$(git rev-parse HEAD)'
            . ' && printf two >> a.txt && git commit -q -am two';
        $lines = 'refs/tags/v9.9.8 $first refs/tags/v9.9.8 ' . self::Z40 . "\n"
            . 'refs/tags/v9.9.9 $(git rev-parse HEAD) refs/tags/v9.9.9 ' . self::Z40;

        [$status, $err] = self::runHook($two, $lines);
        self::assertSame(1, $status);
        self::assertStringContainsString('points at', $err, 'an older tag listed before a HEAD tag was not refused');
    }

    public function testGateOutputGoesToAPrivatePerRunDirectory(): void
    {
        $code = self::executableLines((string) file_get_contents(self::root() . '/.githooks/pre-push'));
        self::assertStringContainsString('mktemp -d', $code);
        self::assertStringNotContainsString('/tmp/pre-push', $code, 'a gate logs to a fixed, predictable path under /tmp');
        self::assertStringNotContainsString('xargs git diff-tree', $code, 'the M2MAP-656 line is back');
    }

    private const Z40 = '0000000000000000000000000000000000000000';

    /**
     * @param string $setup shell run inside a fresh repository on branch main
     * @param string $stdinLine the pre-push stdin line; may reference shell variables from $setup
     * @return array{0: int, 1: string} exit status and stderr of the hook (MAGENTO_ROOT unset)
     */
    private static function runHook(string $setup, string $stdinLine): array
    {
        $dir = sys_get_temp_dir() . '/pp-hook-' . bin2hex(random_bytes(6));
        $err = $dir . '.err';
        $script = implode("\n", [
            'set -e',
            'mkdir -p ' . escapeshellarg($dir),
            'cd ' . escapeshellarg($dir),
            'git init -q -b main .',
            'git config user.email gate@example.test',
            'git config user.name gate',
            $setup,
            'set +e',
            'printf "%s\n" "' . $stdinLine . '" | env -u MAGENTO_ROOT bash '
                . escapeshellarg(self::root() . '/.githooks/pre-push') . ' >/dev/null 2>' . escapeshellarg($err),
            'status=$?',
            'cd /',
            'rm -rf ' . escapeshellarg($dir),
            'exit $status',
        ]);

        $out = [];
        $status = 0;
        exec('bash -c ' . escapeshellarg($script), $out, $status);
        $stderr = '';
        if (is_file($err)) {
            $stderr = (string) file_get_contents($err);
            unlink($err);
        }

        return [$status, $stderr];
    }
}
