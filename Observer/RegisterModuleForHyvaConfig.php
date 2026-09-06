<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Tell Hyvä's `hyva-config` generator where this module's Tailwind sources live.
 *
 * Mirrors Hyva\Theme\Observer\RegisterModuleForHyvaConfig, which registers only
 * Hyva_Theme itself. Hyvä stores then pick up view/frontend/tailwind and the
 * templates it points at, so this module's utility classes survive purging.
 */
class RegisterModuleForHyvaConfig implements ObserverInterface
{
    private const MODULE_NAME = 'BlueFairy_PackagistProvisionerHyva';

    /**
     * @param ComponentRegistrar $componentRegistrar
     */
    public function __construct(
        private readonly ComponentRegistrar $componentRegistrar
    ) {
    }

    /**
     * @param Observer $event
     * @return void
     */
    public function execute(Observer $event): void
    {
        $config = $event->getData('config');
        if ($config === null) {
            return;
        }
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
        if ($path === null) {
            return;
        }
        // BP is Magento's root-path constant, defined at bootstrap; without it a
        // relative path cannot be formed, and a wrong one is worse than none.
        if (!defined('BP')) {
            return;
        }
        $root = (string) constant('BP');
        $extensions = $config->hasData('extensions') ? (array) $config->getData('extensions') : [];
        $extensions[] = ['src' => substr($path, strlen($root) + 1)];
        $config->setData('extensions', $extensions);
    }
}
