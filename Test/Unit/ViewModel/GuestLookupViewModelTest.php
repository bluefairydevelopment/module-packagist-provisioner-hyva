<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\ViewModel;

use BlueFairy\PackagistProvisioner\Model\GuestLookupService;
use BlueFairy\PackagistProvisionerHyva\ViewModel\GuestLookupViewModel;
use Magento\Framework\Data\Form\FormKey;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GuestLookupViewModelTest extends TestCase
{
    /** @var GuestLookupService&MockObject */
    private $service;
    /** @var FormKey&MockObject */
    private $formKey;

    private GuestLookupViewModel $viewModel;

    protected function setUp(): void
    {
        $this->service = $this->createMock(GuestLookupService::class);
        $this->formKey = $this->createMock(FormKey::class);

        $this->viewModel = new GuestLookupViewModel($this->service, $this->formKey);
    }

    /**
     * The template posts this with the lookup. It used to read
     * $block->getData('form_key'), an argument layout never supplies, so it was
     * always '' and every request would have failed CSRF - and the empty string
     * looks exactly like a key until the POST is refused.
     */
    public function testTheFormKeyComesFromTheSessionAndIsNotEmpty(): void
    {
        $this->formKey->method('getFormKey')->willReturn('SESSIONKEY123');

        self::assertSame('SESSIONKEY123', $this->viewModel->getFormKey());
    }

    /**
     * The lookup logic lives in the base package so the Luma form can reach it
     * too. This view model must pass the arguments straight through - a
     * transposed email and order number would refuse every valid lookup.
     */
    public function testTheLookupIsDelegatedWithItsArgumentsInOrder(): void
    {
        $expected = ['success' => true, 'licences' => []];

        $this->service->expects(self::once())
            ->method('lookup')
            ->with('000000004', 'owner@example.com', '203.0.113.7')
            ->willReturn($expected);

        self::assertSame(
            $expected,
            $this->viewModel->lookup('000000004', 'owner@example.com', '203.0.113.7')
        );
    }
}
