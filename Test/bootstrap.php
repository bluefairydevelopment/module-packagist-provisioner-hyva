<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * Unit-test and static-analysis bootstrap for module-packagist-provisioner-hyva.
 *
 * Adapted from the base package's bootstrap; the reasoning behind each guard is
 * recorded there in full. What differs HERE is the second source tree.
 *
 * THIS PACKAGE IS A COMPANION. Every class in it names classes from
 * BlueFairy\PackagistProvisioner - LicenceInterface, LicenceRepositoryInterface,
 * GuestLookupService. Composer maps that namespace to the INSTALLED package in
 * the Magento vendor directory, which is the last thing synced there and not
 * necessarily what is in the sibling checkout. The two are developed in
 * lockstep, so a sibling source tree WINS when one is present: otherwise a green
 * run here can be a green against a base package nobody has looked at, which is
 * the same-path-different-bytes trap one directory over.
 *
 * Which tree was used is reported in BFD_BASE_PACKAGE_SOURCE so a run can say
 * what it measured rather than leaving it to be inferred.
 */

declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: '';

if ($magentoRoot === '' || !is_file($magentoRoot . '/vendor/autoload.php')) {
    fwrite(
        STDERR,
        "MAGENTO_ROOT is not set to a Magento installation.\n"
        . "These tests mock Magento classes, so the framework has to be loadable:\n"
        . "  MAGENTO_ROOT=/path/to/magento vendor/bin/phpunit -c phpunit.xml.dist\n"
    );
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $composerLoader */
$composerLoader = require $magentoRoot . '/vendor/autoload.php';

$hyvaSrc = dirname(__DIR__);

// This package's own classes, ahead of any installed copy.
spl_autoload_register(static function (string $class) use ($hyvaSrc): void {
    $prefix = 'BlueFairy\\PackagistProvisionerHyva\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $hyvaSrc . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

// The base package: sibling checkout if there is one, otherwise whatever
// composer resolves. Stated, not guessed.
$siblingBase = dirname($hyvaSrc) . '/module-packagist-provisioner';
$baseSrc     = is_dir($siblingBase . '/Api') ? $siblingBase : null;

define('BFD_BASE_PACKAGE_SOURCE', $baseSrc ?? 'composer:' . $magentoRoot . '/vendor/bluefairy/module-packagist-provisioner');

if ($baseSrc !== null) {
    spl_autoload_register(static function (string $class) use ($baseSrc): void {
        $prefix = 'BlueFairy\\PackagistProvisioner\\';
        // PackagistProvisionerHyva also starts with that prefix; the guard above
        // has already claimed it, but be explicit rather than order-dependent.
        if (!str_starts_with($class, $prefix) || str_starts_with($class, $prefix . 'Hyva')) {
            return;
        }
        $file = $baseSrc . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }, true, true);
}

$generated = $magentoRoot . '/generated/code/';

foreach (['Magento/Catalog/Api/Data/ProductExtensionInterface.php'] as $sentinel) {
    if (!is_file($generated . $sentinel)) {
        fwrite(STDERR, sprintf(
            "\nREFUSING to run: %s is missing.\n"
            . "The Magento install at %s has no compiled DI, so extension-attribute interfaces\n"
            . "would be synthesised empty and this run would measure phantoms.\n"
            . "Run:  cd %s && php -d memory_limit=-1 bin/magento setup:di:compile\n\n",
            $generated . $sentinel,
            $magentoRoot,
            $magentoRoot
        ));
        exit(1);
    }
}

spl_autoload_register(static function (string $class) use ($generated): void {
    $file = $generated . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Should the bootstrap invent a stand-in for this class? A class composer can
 * find is never synthesised; it is loaded. A blanket "ends in Factory" rule once
 * replaced six real hand-written Magento classes with empty shells in a sibling
 * package, and the error count a release was signed off against turned out to be
 * a measurement of the bootstrap.
 *
 * @param string $class
 * @param \Composer\Autoload\ClassLoader $loader
 * @return bool
 */
function ppHyvaBootstrapShouldSynthesise(string $class, \Composer\Autoload\ClassLoader $loader): bool
{
    $isFactory   = str_ends_with($class, 'Factory');
    $isExtension = str_ends_with($class, 'ExtensionInterface');
    $isExtData   = str_ends_with($class, 'Extension') && !$isExtension;

    if (!$isFactory && !$isExtension && !$isExtData) {
        return false;
    }

    return $loader->findFile($class) === false;
}

spl_autoload_register(static function (string $class) use ($composerLoader): void {
    if (class_exists($class, false) || interface_exists($class, false)) {
        return;
    }

    if (!ppHyvaBootstrapShouldSynthesise($class, $composerLoader)) {
        $realFile = $composerLoader->findFile($class);
        if ($realFile !== false) {
            require $realFile;
        }
        return;
    }

    $isExtension = str_ends_with($class, 'ExtensionInterface');
    $isExtData   = str_ends_with($class, 'Extension') && !$isExtension;

    $pos       = strrpos($class, '\\');
    $namespace = $pos === false ? '' : substr($class, 0, $pos);
    $shortName = $pos === false ? $class : substr($class, $pos + 1);
    $nsDecl    = $namespace === '' ? '' : sprintf('namespace %s;', $namespace);

    if ($isExtension) {
        eval(sprintf('%s interface %s {}', $nsDecl, $shortName));
        return;
    }

    if ($isExtData) {
        $iface      = $class . 'Interface';
        $implements = (interface_exists($iface) || class_exists($iface)) ? ' implements \\' . $iface : '';
        eval(sprintf('%s class %s%s {}', $nsDecl, $shortName, $implements));
        return;
    }

    eval(sprintf(
        '%s class %s { public function create(array $data = []) { return null; } }',
        $nsDecl,
        $shortName
    ));
});
