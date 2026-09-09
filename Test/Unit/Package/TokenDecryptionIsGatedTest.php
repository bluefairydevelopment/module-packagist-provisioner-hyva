<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\Package;

use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Any class in this package that decrypts a licence token must first ask the
 * step-up session whether the customer has re-entered their password.
 *
 * THE DEFECT THIS EXISTS FOR (M2MAP-260, CRITICAL). The base package will not
 * show a token until the customer proves their identity again -
 * Controller/Account/Licences.php returns a redirect unless
 * StepUpSession::isVerified(). This companion showed the SAME decrypted token
 * with no gate whatsoever, on two pages the base package does not even place a
 * block on:
 *
 *   1. the account dashboard  - hyva_customer_account_index.xml -> LicenceListViewModel
 *   2. the order view page    - hyva_sales_order_view.xml       -> OrderLicenceViewModel
 *
 * So one product enforced two different trust boundaries for one value
 * depending only on which theme the merchant had chosen, and the theme our own
 * README recommends for a Hyvä store was the ungated one.
 *
 * WHAT MADE IT WORSE THAN IT LOOKED. Both templates put the plaintext into the
 * HTML unconditionally and used Alpine only to toggle its visibility
 * (x-text="tokenVisible ? '<token>' : '●●●●'"). "Reveal" was never a server
 * round trip, so the token was in view-source of /customer/account/ whether or
 * not anyone pressed the button.
 *
 * WHY A STRUCTURAL CHECK AND NOT ONLY THE BEHAVIOURAL TESTS. The behavioural
 * tests in Test/Unit/ViewModel pin the two view models that exist today. This
 * one asks the question of whoever writes the THIRD one: a new view model that
 * calls decrypt() without taking the gate fails here, and it fails without
 * anyone having to remember this ticket.
 *
 * SCOPE, so the pass is not read as more than it is. It reads the source of
 * every class under ViewModel/, finds those that call ->decrypt(, and requires
 * each to (a) take StepUpSession as a constructor dependency and (b) mention
 * isVerified(. It cannot prove the gate is consulted on the right branch - that
 * is what LicenceListViewModelTest and OrderLicenceViewModelTest are for - and
 * it says nothing about templates.
 */
class TokenDecryptionIsGatedTest extends TestCase
{
    /**
     * The count of token-releasing view models at the time of writing. Asserted
     * as a floor so that a rename, a moved directory or a changed predicate
     * cannot turn this into a check with nothing to examine - which would pass.
     *
     * M2MAP-269 MOVED THIS TEST'S SUBJECT AND THE FLOOR IS WHAT CAUGHT IT.
     * The predicate used to be "the source contains ->decrypt(". Extracting the
     * token-release rule into the base package's TokenReleaseService took the
     * last decrypt() call out of this directory, so the predicate matched
     * NOTHING and the gate assertion below became vacuously true - it would
     * have passed against a companion that showed every token to everybody.
     * The floor turned that into a failure. It was retargeted, not lowered:
     * what a view model does now is CALL the release service, and what must be
     * gated is the authorisation verdict it passes.
     */
    private const MINIMUM_RELEASING_VIEW_MODELS = 2;
    private const MINIMUM_VIEW_MODELS           = 3;

    public function testEveryViewModelThatReleasesATokenPassesTheStepUpVerdict(): void
    {
        $viewModelDir = dirname(__DIR__, 3) . '/ViewModel';
        self::assertDirectoryExists($viewModelDir, 'The ViewModel directory moved; this check is looking at nothing.');

        $files = glob($viewModelDir . '/*.php') ?: [];

        $examined  = 0;
        $releasing = [];
        $ungated   = [];

        foreach ($files as $file) {
            $examined++;
            $class = 'BlueFairy\\PackagistProvisionerHyva\\ViewModel\\' . basename($file, '.php');
            self::assertTrue(class_exists($class), $class . ' does not load.');

            $source = (string) file_get_contents($file);

            // Either shape counts as releasing a token: calling the shared
            // service, or decrypting inline the way this package used to.
            $callsService = str_contains($source, '->release(');
            $decrypts     = str_contains($source, '->decrypt(');
            if (!$callsService && !$decrypts) {
                continue;
            }

            $releasing[] = $class;

            if (!$this->takesStepUpSession($class) || !str_contains($source, 'isVerified(')) {
                $ungated[] = $class . ' (no step-up gate)';
                continue;
            }

            // THE SHARP ONE. Handing the service a literal true is exactly how
            // somebody makes this test green while removing the gate - the
            // constructor argument and the isVerified() mention both survive.
            // On a customer-facing surface the verdict must be a VALUE.
            if (preg_match('/->release\\(\\s*\\$[A-Za-z0-9_>()\\-]+\\s*,\\s*true\\s*\\)/', $source)) {
                $ungated[] = $class . ' (passes a literal true to release())';
            }
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_VIEW_MODELS,
            $examined,
            sprintf('Only %d view models were examined; the search space has shrunk.', $examined)
        );

        self::assertGreaterThanOrEqual(
            self::MINIMUM_RELEASING_VIEW_MODELS,
            count($releasing),
            sprintf(
                'Only %d of %d view models were found to release a token. This check passes '
                . 'vacuously if it finds none, so a drop here is a broken predicate, not good news.',
                count($releasing),
                $examined
            )
        );

        self::assertSame([], $ungated, sprintf(
            'These view models release a licence token without the step-up gate: %s. '
            . '(%d of %d view models release.) The base package will not release this value '
            . 'without password re-entry; neither may the Hyva companion. M2MAP-260, M2MAP-269.',
            implode(', ', $ungated),
            count($releasing),
            $examined
        ));
    }

    private function takesStepUpSession(string $class): bool
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === StepUpSession::class) {
                return true;
            }
        }

        return false;
    }
}
