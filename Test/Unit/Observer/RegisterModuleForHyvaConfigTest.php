<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Observer;

use BlueFairy\PackagistProvisionerHyva\Observer\RegisterModuleForHyvaConfig;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Registers this module's Tailwind sources with Hyvä's config generator
 * (M2MAP-232). Without it every utility class used only by this module's
 * templates is purged from the compiled CSS - the page renders, unstyled, and
 * nothing errors.
 *
 * Note the observer is invisible until `cache:flush`: hyva:config:generate reads
 * event config from the `config` cache and exits 0 either way, so a manual check
 * of this can pass or fail for reasons that have nothing to do with the code.
 * That is precisely why it is pinned here instead.
 */
class RegisterModuleForHyvaConfigTest extends TestCase
{
    private const MODULE   = 'BlueFairy_PackagistProvisionerHyva';
    private const ROOT     = '/var/www/magento';
    private const REL_PATH = 'vendor/bluefairy/module-packagist-provisioner-hyva';

    /** @var ComponentRegistrar&MockObject */
    private $registrar;

    private RegisterModuleForHyvaConfig $observer;

    protected function setUp(): void
    {
        $this->registrar = $this->createMock(ComponentRegistrar::class);
        $this->observer  = new RegisterModuleForHyvaConfig($this->registrar);

        // BP is Magento's root constant, defined at bootstrap and absent from a
        // unit run. Define it once here rather than per test: a constant cannot
        // be unset, so a test that depended on its ABSENCE would silently depend
        // on running first. The absent-BP branch is covered separately, in its
        // own process, for that reason.
        if (!defined('BP')) {
            define('BP', self::ROOT);
        }
    }

    public function testTheModulePathIsAppendedRelativeToTheMagentoRoot(): void
    {
        $this->givenModulePath(self::ROOT . '/' . self::REL_PATH);
        $config = new DataObject();

        $this->observer->execute($this->eventWith($config));

        self::assertSame(
            [['src' => self::REL_PATH]],
            $config->getData('extensions'),
            'Hyvä resolves this against the Magento root, so an absolute path here registers nothing'
        );
    }

    /**
     * Every other module that has already registered must survive. Assigning
     * rather than appending would silently unregister them, and the symptom -
     * some other module''s classes purged - would look like that module''s bug.
     */
    public function testExistingRegistrationsArePreserved(): void
    {
        $this->givenModulePath(self::ROOT . '/' . self::REL_PATH);
        $config = new DataObject(['extensions' => [['src' => 'vendor/someone/else']]]);

        $this->observer->execute($this->eventWith($config));

        self::assertSame(
            [['src' => 'vendor/someone/else'], ['src' => self::REL_PATH]],
            $config->getData('extensions')
        );
    }

    public function testAnEventWithoutAConfigIsIgnored(): void
    {
        $this->registrar->expects(self::never())->method('getPath');

        $this->observer->execute(new Observer());

        $this->addToAssertionCount(1);
    }

    /**
     * A module that is not registered has no path. Building one from null would
     * produce a relative path from a substr() of the empty string - a plausible
     * looking value pointing nowhere.
     */
    public function testAnUnregisteredModuleRegistersNothing(): void
    {
        $this->givenModulePath(null);
        $config = new DataObject();

        $this->observer->execute($this->eventWith($config));

        self::assertNull($config->getData('extensions'));
    }

    private function givenModulePath(?string $path): void
    {
        $this->registrar->method('getPath')
            ->with(ComponentRegistrar::MODULE, self::MODULE)
            ->willReturn($path);
    }

    /**
     * The event manager sets the observer's OWN data from the dispatch array, so
     * the config sits at the top level - `new Observer(['data' => ...])` nests it
     * one level too deep and getData('config') quietly returns null, which the
     * observer treats as "no config" and returns. That reads as the observer
     * doing nothing rather than as a broken fixture.
     */
    private function eventWith(DataObject $config): Observer
    {
        return new Observer(['config' => $config]);
    }
}
