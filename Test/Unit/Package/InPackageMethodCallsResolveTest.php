<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

/**
 * Every `$this->someService->someMethod()` whose property is typed as a class or
 * interface INSIDE this package must name a method that actually exists.
 *
 * THE DEFECT THIS EXISTS FOR (M2MAP-238, generalising a CRITICAL that shipped
 * in the BASE package - this is the companion's copy of the same check).
 * Controller/Adminhtml/Licence/Regenerate.php called
 *
 *     $this->apiClient->regenerateToken(...)
 *
 * and PackagistApiClient has never had a regenerateToken(). PHP does not resolve
 * a method name until the line executes, so the file parsed, the module
 * installed, the admin grid rendered its "Regenerate" action, and four documents
 * promised the feature. Pressing it raised
 *
 *     Error: Call to undefined method ...\PackagistApiClient::regenerateToken()
 *
 * and because Error is not Exception, the controller's own `catch (\Exception)`
 * did not catch it: the admin got a 500 with no message. The feature had never
 * worked for anyone.
 *
 * WHY A STATIC CHECK RATHER THAN A UNIT TEST OF THE CONTROLLER. A unit test
 * would have to be written by someone who already suspected that line. This one
 * needs nobody to have thought of it in advance - it reads every call written
 * against an in-package service and asks whether the method is there. It is the
 * same shape as ClassConstantsResolveTest, one level up.
 *
 * WHY ONLY IN-PACKAGE TYPES. Magento is full of legitimate magic: __call
 * getters on AbstractModel, generated extension attributes, interceptors. A
 * sweep over every type would drown in false positives and be turned off within
 * a week. Restricted to types this package itself declares, the question "does
 * this method exist" has a definite answer.
 *
 * SCOPE, stated so the pass is not read as more than it is. This resolves calls
 * written as `$this->property->method(`, where `property` carries a declared
 * (or constructor-promoted) type naming one in-package class or interface that
 * does not itself declare __call. It cannot see calls on local variables, on
 * untyped or union-typed properties, on a factory's ->create() result, or a
 * method name assembled at runtime, and it says nothing about ARGUMENTS. It
 * catches the class of defect that shipped, not every possible one.
 */
class InPackageMethodCallsResolveTest extends TestCase
{
    /**
     * This package's own PSR-4 root, per composer.json.
     */
    private const PREFIX = 'BlueFairy\\PackagistProvisionerHyva\\';

    /**
     * Namespaces whose methods this check will assert on. The BASE package is
     * in here deliberately: three of the four classes here inject a base
     * service, and a companion calling a base method that has been renamed or
     * never existed is exactly the break nobody would see until a shopper
     * opened My Extensions on a Hyva store.
     */
    private const IN_PACKAGE = [
        'BlueFairy\\PackagistProvisionerHyva\\',
        'BlueFairy\\PackagistProvisioner\\',
    ];

    /**
     * Directories that are not part of the shipped package.
     */
    private const SKIP = ['/Test/', '/vendor/', '/docs/'];

    public function testEveryMethodCalledOnAnInPackageServiceExists(): void
    {
        $files = $this->packageFiles();

        // A search space that is empty answers "no violations" for the wrong
        // reason. Fail loudly rather than pass on nothing.
        $this->assertGreaterThan(
            3,
            count($files),
            'the file sweep found almost nothing, so a clean result would mean nothing'
        );

        $missing    = [];
        $callSites  = 0;   // every $this->prop->method( in the package
        $checked    = 0;   // those whose property is an in-package type
        $classes    = 0;   // package classes that could be reflected

        foreach ($files as $file) {
            $fqcn = $this->classNameFor($file);
            if ($fqcn === null) {
                continue;
            }

            $properties = $this->inPackagePropertyTypes($fqcn);
            if ($properties === null) {
                continue;
            }
            $classes++;

            foreach ($this->propertyCalls($file) as [$property, $method, $line]) {
                $callSites++;

                if (!isset($properties[$property])) {
                    continue;
                }

                $checked++;
                $type = $properties[$property];

                if (!method_exists($type, $method)) {
                    $missing[] = sprintf(
                        '%s:%d  $this->%s->%s()  -  %s has no %s()',
                        substr($file, strlen(dirname(__DIR__, 3)) + 1),
                        $line,
                        $property,
                        $method,
                        $type,
                        $method
                    );
                }
            }
        }

        // Each of these is a denominator. Without them "0 missing" is
        // compatible with having examined nothing: a renamed namespace prefix,
        // an autoloader that stopped resolving, a tokeniser that stopped
        // matching would each produce a silent, permanent pass.
        $this->assertGreaterThan(
            3,
            $classes,
            'almost no package classes could be reflected, so nothing was really checked'
        );
        $this->assertGreaterThan(
            5,
            $callSites,
            'almost no $this->property->method() calls were found, so the parser is not reading these files'
        );
        $this->assertGreaterThan(
            2,
            $checked,
            'almost no calls landed on an in-package type, so the type filter is matching nothing'
        );

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "%d call(s) name a method that does not exist on an in-package service.\n"
                . "Examined %d classes, %d property calls, %d of them on in-package types:\n  %s",
                count($missing),
                $classes,
                $callSites,
                $checked,
                implode("\n  ", $missing)
            )
        );
    }

    /**
     * @return string[]
     */
    private function packageFiles(): array
    {
        $root  = dirname(__DIR__, 3);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->getExtension() !== 'php') {
                continue;
            }
            $path = $entry->getPathname();
            foreach (self::SKIP as $skip) {
                if (str_contains($path, $skip)) {
                    continue 2;
                }
            }
            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * The PSR-4 class name a package file declares, or null if it declares none
     * (registration.php) or the class cannot be loaded here.
     *
     * @param string $file
     * @return string|null
     */
    private function classNameFor(string $file): ?string
    {
        $root     = dirname(__DIR__, 3);
        $relative = substr($file, strlen($root) + 1, -strlen('.php'));
        $fqcn     = self::PREFIX . str_replace('/', '\\', $relative);

        try {
            if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
                return null;
            }
        } catch (Throwable $e) {
            // A class whose parent is not loadable in this bootstrap is out of
            // scope: the failure would be about the autoloader, not the code.
            return null;
        }

        return $fqcn;
    }

    /**
     * property name => in-package type, for one class.
     *
     * Includes constructor-promoted properties, which is how every service in
     * this package is injected. Returns null if the class cannot be reflected.
     *
     * @param string $fqcn
     * @return array<string, string>|null
     */
    private function inPackagePropertyTypes(string $fqcn): ?array
    {
        try {
            $reflection = new ReflectionClass($fqcn);
        } catch (Throwable $e) {
            return null;
        }

        $types = [];

        foreach ($reflection->getProperties() as $property) {
            /** @var ReflectionProperty $property */
            $type = $property->getType();

            // Union and intersection types have no single answer; an untyped
            // property has none at all.
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();
            $inPackage = false;
            foreach (self::IN_PACKAGE as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $inPackage = true;
                    break;
                }
            }
            if (!$inPackage) {
                continue;   // Magento's own magic is out of scope, by design
            }
            if (!class_exists($name) && !interface_exists($name)) {
                continue;
            }
            // A class that answers anything, answers everything. Licence
            // extends AbstractModel, whose __call serves getPackageName() and
            // every other data getter without declaring one.
            if (method_exists($name, '__call')) {
                continue;
            }

            $types[$property->getName()] = $name;
        }

        return $types;
    }

    /**
     * Every `$this->property->method(` written in one file.
     *
     * @param string $file
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function propertyCalls(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $found  = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
                continue;
            }

            $arrowOne = $this->nextMeaningfulIndex($tokens, $i);
            if ($arrowOne === null || !$this->isObjectOperator($tokens[$arrowOne])) {
                continue;
            }

            $propertyIdx = $this->nextMeaningfulIndex($tokens, $arrowOne);
            if ($propertyIdx === null || !$this->isName($tokens[$propertyIdx])) {
                continue;
            }

            $arrowTwo = $this->nextMeaningfulIndex($tokens, $propertyIdx);
            if ($arrowTwo === null || !$this->isObjectOperator($tokens[$arrowTwo])) {
                continue;   // $this->property on its own, or ->property[...]
            }

            $methodIdx = $this->nextMeaningfulIndex($tokens, $arrowTwo);
            if ($methodIdx === null || !$this->isName($tokens[$methodIdx])) {
                continue;   // ->$dynamic, ->{...}
            }

            // The token AFTER the name is what makes it a call rather than a
            // nested property read.
            $afterIdx = $this->nextMeaningfulIndex($tokens, $methodIdx);
            if ($afterIdx === null || $tokens[$afterIdx] !== '(') {
                continue;
            }

            $found[] = [
                $tokens[$propertyIdx][1],
                $tokens[$methodIdx][1],
                $tokens[$methodIdx][2],
            ];
        }

        return $found;
    }

    /**
     * @param mixed $token
     * @return bool
     */
    private function isObjectOperator($token): bool
    {
        if (!is_array($token)) {
            return false;
        }

        // T_NULLSAFE_OBJECT_OPERATOR reads the same way for this purpose.
        return $token[0] === T_OBJECT_OPERATOR
            || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $token[0] === T_NULLSAFE_OBJECT_OPERATOR);
    }

    /**
     * @param mixed $token
     * @return bool
     */
    private function isName($token): bool
    {
        return is_array($token) && $token[0] === T_STRING;
    }

    /**
     * Index of the next token that is not whitespace or a comment.
     *
     * @param array<int, mixed> $tokens
     * @param int $i
     * @return int|null
     */
    private function nextMeaningfulIndex(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }

        return null;
    }
}
