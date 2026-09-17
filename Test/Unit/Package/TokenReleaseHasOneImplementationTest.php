<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * M2MAP-269. THE TOKEN-RELEASE RULE MUST EXIST EXACTLY ONCE.
 *
 * "A licence yields its decrypted token only when its status is ACTIVE, and a
 * decryption failure degrades to an empty string rather than throwing" is the
 * authorisation boundary that stops a revoked or expired licence handing out a
 * working credential. It was implemented FOUR times across the two packages,
 * and nothing forced the four to agree - change one and the other three keep
 * the old rule silently, with no test and no type failing. One theme would then
 * release a token the other correctly withholds.
 *
 * This test does not check that the rule is CORRECT. It checks that there is
 * only one of it, which is the property a unit test of the service cannot give
 * you: a second copy passes every test the first one does.
 */
class TokenReleaseHasOneImplementationTest extends TestCase
{
    /**
     * Files allowed to turn a licence token back into plaintext.
     *
     * @var string[]
     */
    private const AUTHORISED = [];

    /** Floor: below this the file sweep is not reading the package. */
    private const MINIMUM_FILES = 10;

    public function testNothingButTheReleaseServiceDecryptsALicenceToken(): void
    {
        $root  = dirname(__DIR__, 3);
        $files = $this->sourceFiles($root);

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_FILES,
            count($files),
            'the file sweep found almost nothing, so a clean result would mean nothing'
        );

        // THE NEEDLE CONTROL. This package authorises nothing, so nothing in it can
        // prove the needle works; the base package's release service is the specimen.
        // Its path is resolved through composer's vendor layout OR the sibling checkout.
        $needle = '->decrypt(';
        $base   = self::baseReleaseService($root);
        $this->assertIsString($base, 'CONTROL: the base package\'s TokenReleaseService could not be found, so the needle is unproven');
        $this->assertStringContainsString($needle, (string) file_get_contents($base), 'CONTROL: the needle does not match the base release service');

        $offenders = [];
        foreach ($files as $file) {
            $relative = substr($file, strlen($root) + 1);
            if (in_array($relative, self::AUTHORISED, true)) {
                continue;
            }
            if (str_contains((string) file_get_contents($file), $needle)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            sprintf(
                "%d file(s) decrypt a token outside the shared release service. Nothing forces a "
                . "second copy to agree with the first: add a status, or a null check, or decide "
                . "EXPIRED should be withheld too, and the copies drift silently. Examined %d "
                . "files; authorised here: %s\n  %s",
                count($offenders),
                count($files),
                self::AUTHORISED === [] ? '(none - this package must not decrypt at all)'
                    : implode(', ', self::AUTHORISED),
                implode("\n  ", $offenders)
            )
        );
    }

    /** The base package's release service: the sibling checkout first, then composer's vendor. */
    private static function baseReleaseService(string $root): ?string
    {
        foreach ([
            dirname($root) . '/module-packagist-provisioner/Model/TokenReleaseService.php',
            dirname($root, 2) . '/module-packagist-provisioner/Model/TokenReleaseService.php',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $magentoRoot = getenv('MAGENTO_ROOT') ?: '';
        $vendor = $magentoRoot . '/vendor/bluefairy/module-packagist-provisioner/Model/TokenReleaseService.php';
        return is_file($vendor) ? $vendor : null;
    }

    /**
     * @param string $root
     * @return string[]
     */
    private function sourceFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') && !str_ends_with($path, '.phtml')) {
                continue;
            }
            if (str_contains($path, '/Test/') || str_contains($path, '/vendor/')) {
                continue;
            }
            $files[] = $path;
        }

        sort($files);
        return $files;
    }
}
