<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use Magento\Customer\Model\Session;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class LicenceListViewModel implements ArgumentInterface
{
    /**
     * @param Session $customerSession
     * @param LicenceRepositoryInterface $licenceRepository
     * @param EncryptorInterface $encryptor
     * @param StepUpSession $stepUpSession
     */
    public function __construct(
        private readonly Session $customerSession,
        private readonly LicenceRepositoryInterface $licenceRepository,
        private readonly EncryptorInterface $encryptor,
        // M2MAP-260. The base package refuses to show a token until the customer
        // re-enters their password; this block sits on the account dashboard,
        // which is not behind that gate, so it has to ask for itself.
        private readonly StepUpSession $stepUpSession
    ) {
    }

    /**
     * Has the customer re-entered their password recently enough to be shown a
     * token? The template asks this so it can offer the way through rather than
     * silently omitting the credentials.
     *
     * @return bool
     */
    public function isStepUpVerified(): bool
    {
        return $this->stepUpSession->isVerified();
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

        // Read once, not per licence: isVerified() is time-based, and a list
        // spanning the expiry boundary would gate some rows and not others.
        $verified = $this->isStepUpVerified();

        $licences = $this->licenceRepository->getByCustomerId($customerId);
        $result = [];

        foreach ($licences as $licence) {
            $decryptedToken = '';
            // The gate is applied HERE rather than in the template so that the
            // plaintext is never produced at all when it may not be shown. The
            // templates put the token into the HTML and use Alpine only to
            // toggle its visibility, so anything decrypted here reaches
            // view-source whether or not a button is pressed.
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
