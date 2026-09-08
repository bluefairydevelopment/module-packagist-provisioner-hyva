<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\ViewModel;

use BlueFairy\PackagistProvisioner\Model\GuestLookupService;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class GuestLookupViewModel implements ArgumentInterface
{
    /**
     * @param GuestLookupService $guestLookupService
     * @param FormKey $formKey
     */
    public function __construct(
        private readonly GuestLookupService $guestLookupService,
        private readonly FormKey $formKey
    ) {
    }

    /**
     * The template posts this with the lookup. Without it the request fails
     * CSRF validation, and the block was never given a `form_key` argument in
     * layout — so `$block->getData('form_key')` was always the empty string.
     */
    public function getFormKey(): string
    {
        return (string) $this->formKey->getFormKey();
    }

    /**
     * Retained so anything already calling the view model keeps working; the
     * logic itself now lives in the base module, where the Luma form can reach
     * it too.
     *
     * @param string $orderIncrementId
     * @param string $email
     * @param string $ip
     * @return array{success: bool, licences?: array<int, array<string, mixed>>, error?: string}
     */
    public function lookup(string $orderIncrementId, string $email, string $ip): array
    {
        return $this->guestLookupService->lookup($orderIncrementId, $email, $ip);
    }
}
