<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class OrderLicenceViewModel implements ArgumentInterface
{
    /**
     * @param LicenceRepositoryInterface $licenceRepository
     * @param EncryptorInterface $encryptor
     * @param StepUpSession $stepUpSession
     */
    public function __construct(
        private readonly LicenceRepositoryInterface $licenceRepository,
        private readonly EncryptorInterface $encryptor,
        // M2MAP-260, as on LicenceListViewModel. The order view is the second
        // ungated route to the same value.
        private readonly StepUpSession $stepUpSession
    ) {
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
            $decryptedToken = '';
            // Gated before decryption, not before display - see the note on
            // LicenceListViewModel::getLicences().
            $isActive = $licence->getStatus() === LicenceInterface::STATUS_ACTIVE;
            if ($verified && $isActive && $licence->getToken() !== null) {
                try {
                    $decryptedToken = $this->encryptor->decrypt($licence->getToken());
                } catch (\Exception) {
                    $decryptedToken = '';
                }
            }

            $result[] = [
                'licence'         => $licence,
                'decrypted_token' => $decryptedToken,
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
