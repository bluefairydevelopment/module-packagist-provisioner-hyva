<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class LicenceListViewModel implements ArgumentInterface
{
    /**
     * @param Session $customerSession
     * @param LicenceRepositoryInterface $licenceRepository
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly Session $customerSession,
        private readonly LicenceRepositoryInterface $licenceRepository,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * @return array<int, array{licence: LicenceInterface, decrypted_token: string}>
     */
    public function getLicences(): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId === 0) {
            return [];
        }

        $licences = $this->licenceRepository->getByCustomerId($customerId);
        $result = [];

        foreach ($licences as $licence) {
            $decryptedToken = '';
            if ($licence->getStatus() === LicenceInterface::STATUS_ACTIVE && $licence->getToken() !== null) {
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

    public function isActive(LicenceInterface $licence): bool
    {
        return $licence->getStatus() === LicenceInterface::STATUS_ACTIVE;
    }

    public function isExpired(LicenceInterface $licence): bool
    {
        return $licence->getStatus() === LicenceInterface::STATUS_EXPIRED;
    }

    public function isRevoked(LicenceInterface $licence): bool
    {
        return $licence->getStatus() === LicenceInterface::STATUS_REVOKED;
    }

    public function isFailed(LicenceInterface $licence): bool
    {
        return $licence->getStatus() === LicenceInterface::STATUS_FAILED;
    }

    public function isPending(LicenceInterface $licence): bool
    {
        return $licence->getStatus() === LicenceInterface::STATUS_PENDING;
    }
}
