<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\ViewModel;

use PHPUnit\Framework\TestCase;

/**
 * Base 1.2.4 made ExpiryDate::format() an instance method. A template that still calls it statically is a fatal
 * error on the customer dashboard, so the date goes through the view model and no file here calls it statically.
 */
class ExpiryIsFormattedThroughTheViewModelTest extends TestCase
{
    public function testTheDashboardTemplateFormatsThroughTheViewModel(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/view/frontend/templates/account/extensions.phtml');
        self::assertStringContainsString('$viewModel->formatExpiry($licence->getExpiresAt())', $template);
    }

    public function testNoFileCallsTheFormatterStatically(): void
    {
        $root = dirname(__DIR__, 3);
        $examined = 0;
        $static = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file;
            if (!preg_match('/\.(php|phtml)$/', $path) || str_contains($path, '/Test/') || str_contains($path, '/vendor/')) {
                continue;
            }
            $examined++;
            if (str_contains((string) file_get_contents($path), 'ExpiryDate::format(')) {
                $static[] = substr($path, strlen($root) + 1);
            }
        }
        self::assertGreaterThan(5, $examined, 'too few files examined to believe the scan read the package');
        self::assertSame([], $static);
    }

    public function testControlTheScanFindsAStaticCall(): void
    {
        self::assertStringContainsString('ExpiryDate::format(', "<?= ExpiryDate::format(\$x) ?>");
    }
}
