<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use BlueFairy\PackagistProvisioner\Model\GuestLookupService;
use BlueFairy\PackagistProvisioner\Model\TokenReleaseService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M2MAP-649. This package's view models are constructed with base classes
 * under `bluefairy/module-packagist-provisioner: ^1.2`. Those classes carry
 * `@api` in the base, and this holds the exact methods and signatures this
 * package calls, so a base release that changes one fails here rather than on
 * a merchant's store.
 *
 * THE CONSTRAINT FLOOR IS PART OF THE CONTRACT, and pinning method signatures
 * does not protect it. At 1.2.0 this package began referencing
 * `Model\ExpiryDate` and `ViewModel\SupportContact`, NEITHER OF WHICH EXISTS
 * AT BASE v1.1.0 - while the constraint still said `^1.1`. Composer was free
 * to resolve base 1.1.0 beside this package and the Hyva storefront would have
 * fatalled on a missing class. That is the same defect as the `^1.0` corrected
 * in 1.1.0, one release later, so `testEveryBaseClassIsDeclared()` below now
 * derives the list from the source instead of trusting a hand-kept one.
 */
class TheBaseContractThisPackageConstructsTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string, 2: list<string>, 3: string}>
     */
    public static function contract(): array
    {
        return [
            'GuestLookupService::lookup'   => [GuestLookupService::class, 'lookup', ['string', 'string', 'string'], 'array'],
            'TokenReleaseService::release' => [TokenReleaseService::class, 'release', [\BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface::class, 'bool'], 'string'],
            'StepUpSession::isVerified'    => [StepUpSession::class, 'isVerified', [], 'bool'],
        ];
    }

    /**
     * @dataProvider contract
     * @param class-string $class
     * @param list<string> $paramTypes
     */
    #[DataProvider('contract')]
    public function testTheBaseStillHonoursTheSignatureThisPackageCalls(string $class, string $method, array $paramTypes, string $return): void
    {
        $ref = new \ReflectionMethod($class, $method);
        self::assertSame($paramTypes, array_map(static fn (\ReflectionParameter $p): string => (string) $p->getType(), $ref->getParameters()));
        self::assertSame($return, (string) $ref->getReturnType());

        $doc = (string) (new \ReflectionClass($class))->getDocComment();
        self::assertStringContainsString('@api', $doc, $class . ' is not marked @api in the base - the contract is unannounced');
    }

    /** CONTROL: a base class this package does not construct is NOT expected to carry the marker. */
    public function testTheMarkerIsNotEverywhere(): void
    {
        $doc = (string) (new \ReflectionClass(\BlueFairy\PackagistProvisioner\Model\Mailer::class))->getDocComment();
        self::assertStringNotContainsString('@api', $doc);
    }

    /**
     * EVERY base class this package names must be declared here, and the
     * declaration carries the base version that first shipped it.
     *
     * This is the gate the `^1.1` slip needed. Signature pinning asks "does
     * this method still look the same"; it cannot ask "is this class in the
     * version my constraint admits", because the test runs against whatever
     * base happens to be installed - which, in development, is always the new
     * one. Deriving the list from the source means a NEW base dependency
     * cannot be added silently: it fails here, and whoever adds it has to say
     * which base version introduced it and check the floor in composer.json.
     */
    public function testEveryBaseClassIsDeclared(): void
    {
        // class => the base version that first shipped it. Model\Mailer is
        // deliberately ABSENT: this package never constructs it, which is
        // exactly why testTheMarkerIsNotEverywhere() uses it as its control.
        $declared = [
            'Api\\Data\\LicenceInterface'           => '1.0.0',
            'Api\\LicenceRepositoryInterface'       => '1.0.0',
            'Model\\Auth\\StepUpSession'            => '1.1.0',
            'Model\\Config'                         => '1.0.0',
            'Model\\ExpiryDate'                     => '1.2.4',
            'Model\\GuestLookupService'             => '1.1.0',
            'Model\\TokenReleaseService'            => '1.1.0',
            'ViewModel\\Account\\LicencesViewModel' => '1.0.0',
            'ViewModel\\SupportContact'             => '1.2.0',
        ];

        $found = [];
        foreach ($this->productionFiles() as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/BlueFairy\\\\PackagistProvisioner\\\\([A-Za-z\\\\]+)/', $source, $m);
            foreach ($m[1] as $class) {
                // This package's own namespace starts with "Hyva" after the
                // shared prefix; only the BASE package's classes matter here.
                if (str_starts_with($class, 'Hyva')) {
                    continue;
                }
                $found[$class] = true;
            }
        }
        $found = array_keys($found);
        sort($found);

        // The control. An empty sweep would make the comparison below pass
        // while proving nothing, and a path change is exactly what empties it.
        self::assertNotEmpty(
            $found,
            'no production file references a base class at all. This package is built ON the base '
            . 'package - an empty result means this sweep has lost its subject, not that the '
            . 'dependency is gone. Retarget it; do not delete it.'
        );

        $undeclared = array_diff($found, array_keys($declared));
        self::assertSame(
            [],
            array_values($undeclared),
            sprintf(
                "this package references a base class that is not declared above:\n  %s\n\n"
                . "Add it to \$declared WITH the base version that first shipped it, then check "
                . "composer.json: if that version is newer than the constraint floor (currently "
                . "%s), the floor must be raised. Composer will otherwise resolve an older base "
                . "that does not contain the class, and the storefront fatals.",
                implode("\n  ", $undeclared),
                $this->constraintFloor()
            )
        );

        $stale = array_diff(array_keys($declared), $found);
        self::assertSame(
            [],
            array_values($stale),
            "declared above but no longer referenced:\n  " . implode("\n  ", $stale)
                . "\n\nRemove it, so this list keeps describing what the package actually needs."
        );
    }

    /**
     * The floor must admit every declared class.
     *
     * Read the highest "first shipped in" version above and require the
     * constraint floor to be at least that. This is the assertion that would
     * have gone red on `^1.1` the moment ExpiryDate was used.
     */
    public function testTheConstraintFloorAdmitsEveryClassThisPackageUses(): void
    {
        $floor = $this->constraintFloor();

        self::assertNotSame('', $floor, 'no bluefairy/module-packagist-provisioner constraint in composer.json.');

        // Highest version named in the declaration above, read back from the
        // test itself so the two cannot drift apart.
        $source = (string) file_get_contents(__FILE__);
        preg_match_all("/=> '(\d+\.\d+\.\d+)'/", $source, $m);
        $required = '0.0.0';
        foreach ($m[1] as $v) {
            if (version_compare($v, $required, '>')) {
                $required = $v;
            }
        }

        self::assertNotSame('0.0.0', $required, 'no versions parsed out of the declaration - retarget this.');

        self::assertTrue(
            version_compare($floor, $required, '>='),
            sprintf(
                'composer.json requires the base at a floor of %s, but this package uses a class '
                . 'that first shipped in base %s. Composer may resolve a base without it and the '
                . 'Hyva storefront fatals on a missing class. Raise the constraint.',
                $floor,
                $required
            )
        );
    }

    /** The major.minor floor of the base constraint, as a comparable version. */
    private function constraintFloor(): string
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true
        );
        $raw = $composer['require']['bluefairy/module-packagist-provisioner'] ?? '';

        // The patch counts: "^1.2.4" is a floor of 1.2.4, not 1.2.0.
        return preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $raw, $m)
            ? $m[1] . '.' . $m[2] . '.' . ($m[3] ?? '0')
            : '';
    }

    /** @return string[] */
    private function productionFiles(): array
    {
        $root  = dirname(__DIR__, 3);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if (!preg_match('/\.(php|phtml|xml)$/', $path)) {
                continue;
            }
            if (str_contains($path, '/Test/') || str_contains($path, '/vendor/')) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }
}
