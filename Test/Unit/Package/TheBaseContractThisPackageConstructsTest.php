<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use BlueFairy\PackagistProvisioner\Model\GuestLookupService;
use BlueFairy\PackagistProvisioner\Model\TokenReleaseService;
use PHPUnit\Framework\TestCase;

/**
 * M2MAP-649. This package's view models are constructed with three base
 * classes under `bluefairy/module-packagist-provisioner: ^1.1`, which admits
 * every 1.x. Those classes now carry `@api` in the base, and this holds the
 * exact methods and signatures this package calls, so a base release that
 * changes one fails here rather than on a merchant's store.
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
}
