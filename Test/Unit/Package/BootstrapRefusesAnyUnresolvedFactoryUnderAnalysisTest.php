<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * Runs the REAL Test/bootstrap.php in a PHP subprocess against a planted
 * fixture: a fake Magento root whose composer loader knows nothing, and a fake package
 * whose one shipped file imports Magento\Nowhere\ThingFactory - a FOREIGN factory that
 * nothing can resolve. Under static analysis the bootstrap must refuse and name it;
 * under the unit suite it must still synthesise; and a resolvable factory must pass.
 *
 * Seen red before it counted: against the bootstrap from before this guard the first
 * test failed (the old bootstrap exited 0 over the foreign stand-in).
 */
class BootstrapRefusesAnyUnresolvedFactoryUnderAnalysisTest extends TestCase
{
    private const FOREIGN = 'Magento\\Nowhere\\ThingFactory';

    /** Generated files the bootstrap's other rules require before it reaches the scan. */
    private const SENTINELS = ['Magento/Catalog/Api/Data/ProductExtensionInterface.php'];

    /** @var string */
    private string $dir = '';

    protected function setUp(): void
    {
        $realRoot = (string) getenv('MAGENTO_ROOT');
        $classLoader = $realRoot . '/vendor/composer/ClassLoader.php';
        self::assertFileExists($classLoader, 'the fixture borrows composer\'s ClassLoader from MAGENTO_ROOT');

        $this->dir = sys_get_temp_dir() . '/m2map682-' . bin2hex(random_bytes(6));
        $root = $this->dir . '/magento';
        $this->write($root . '/vendor/autoload.php', sprintf(
            "<?php\nrequire_once %s;\nreturn new \\Composer\\Autoload\\ClassLoader();\n",
            var_export($classLoader, true)
        ));
        foreach (self::SENTINELS as $sentinel) {
            $this->write($root . '/generated/code/' . $sentinel, "<?php\n");
        }

        $package = $this->dir . '/package';
        $this->write(
            $package . '/Test/bootstrap.php',
            (string) file_get_contents(dirname(__DIR__, 3) . '/Test/bootstrap.php')
        );
        $this->write(
            $package . '/Model/Probe.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nuse " . self::FOREIGN . ";\n\nclass Probe\n{\n}\n"
        );

        // Two entry points into the same bootstrap: one whose argv[0] names phpstan
        // (the bootstrap's analysis test), one that does not (the unit-suite path).
        $require = sprintf("<?php\nrequire %s;\nexit(0);\n", var_export($package . '/Test/bootstrap.php', true));
        $this->write($this->dir . '/run-phpstan-probe.php', $require);
        $this->write($this->dir . '/run-unit-probe.php', $require);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($walk as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testAnalysisRefusesAForeignUnresolvedFactoryAndNamesIt(): void
    {
        [$exit, $stderr] = $this->runProbe('run-phpstan-probe.php');

        self::assertNotSame(0, $exit, "the analyser must be refused:\n" . $stderr);
        self::assertStringContainsString(self::FOREIGN, $stderr);
        self::assertStringContainsString('bin/magento setup:di:compile', $stderr);
        self::assertStringContainsString('1 unresolved [static analysis]', $stderr);
    }

    public function testTheUnitSuiteStillSynthesisesTheSameFactory(): void
    {
        [$exit, $stderr] = $this->runProbe('run-unit-probe.php');

        self::assertSame(0, $exit, "the unit suite mocks factories and must not be refused:\n" . $stderr);
        self::assertStringContainsString('1 unresolved', $stderr);
        self::assertStringNotContainsString('REFUSING', $stderr);
    }

    public function testAnalysisPassesWhenTheFactoryResolvesFromGeneratedCode(): void
    {
        $this->write(
            $this->dir . '/magento/generated/code/Magento/Nowhere/ThingFactory.php',
            "<?php\nnamespace Magento\\Nowhere;\nclass ThingFactory\n{\n}\n"
        );

        [$exit, $stderr] = $this->runProbe('run-phpstan-probe.php');

        self::assertSame(0, $exit, "a resolvable factory must not be refused:\n" . $stderr);
        self::assertStringContainsString('1 Factory/Extension import(s) in shipped code, 0 unresolved', $stderr);
    }

    /**
     * @param string $script
     * @return array{0: int, 1: string}
     */
    private function runProbe(string $script): array
    {
        $env = getenv();
        $env['MAGENTO_ROOT'] = $this->dir . '/magento';
        $process = proc_open(
            [PHP_BINARY, '-n', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            $env
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stderr];
    }

    private function write(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
