<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use BlueFairy\PackagistProvisioner\Model\TokenReleaseService;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class OrderLicenceViewModel implements ArgumentInterface
{
    /**
     * @param LicenceRepositoryInterface $licenceRepository
     * @param TokenReleaseService $tokenRelease
     * @param StepUpSession $stepUpSession
     */
    public function __construct(
        private readonly LicenceRepositoryInterface $licenceRepository,
        private readonly TokenReleaseService $tokenRelease,
        // M2MAP-260, as on LicenceListViewModel. The order view is the second
        // ungated route to the same value.
        private readonly StepUpSession $stepUpSession,
        // M2MAP-624. The order being viewed. Magento\Sales\Block\Order\View and the
        // order-view controller put it in the registry as `current_order`; that is
        // what every core order-view block reads, and it is the customer's own
        // order by construction (the controller has already refused any other).
        // NEVER a request parameter: an `order_id` read from the request would
        // let a signed-in customer render another customer's credentials.
        private readonly Registry $registry
    ) {
    }

    /**
     * The entity id of the order on the current order-view page, or 0 off it.
     *
     * Through 1.1.0 the template read `$block->getData('order_id')`, which no
     * layout ever set, so this block had never rendered for anyone (M2MAP-624).
     *
     * @return int
     */
    public function getCurrentOrderId(): int
    {
        $order = $this->registry->registry('current_order');
        return $order instanceof OrderInterface ? (int) $order->getEntityId() : 0;
    }

    /**
     * @return bool
     */
    public function isStepUpVerified(): bool
    {
        return $this->stepUpSession->isVerified();
    }

    /**
     * @param int $orderId
     * @return array<int, array{licence: LicenceInterface, decrypted_token: string}>
     */
    public function getLicencesForOrder(int $orderId): array
    {
        $verified = $this->isStepUpVerified();

        $licences = $this->licenceRepository->getByOrderId($orderId);
        $result = [];

        foreach ($licences as $licence) {
            // M2MAP-269, and gated before decryption rather than before
            // display - see the note on LicenceListViewModel::getLicences().
            $result[] = [
                'licence'         => $licence,
                'decrypted_token' => $this->tokenRelease->release($licence, $verified),
            ];
        }

        return $result;
    }

    public function getPendingMessage(LicenceInterface $licence): string
    {
        return sprintf(
            'Your credentials for %s are being prepared. This usually takes less than 2 minutes. '
            . 'If this message persists after 15 minutes, contact support@bluefairydevelopment.com with Order #%d.',
            $licence->getPackageName(),
            $licence->getOrderId()
        );
    }

    public function getFailedMessage(LicenceInterface $licence): string
    {
        return sprintf(
            'There was a problem provisioning your credentials for %s — Reference: Order #%d. '
            . 'Please contact support@bluefairydevelopment.com.',
            $licence->getPackageName(),
            $licence->getOrderId()
        );
    }
}
