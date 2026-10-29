<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use BlueFairy\PackagistProvisioner\Model\ExpiryDate;
use Magento\Customer\Model\Session;
use BlueFairy\PackagistProvisioner\Model\TokenReleaseService;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class LicenceListViewModel implements ArgumentInterface
{
    /**
     * @param Session $customerSession
     * @param LicenceRepositoryInterface $licenceRepository
     * @param TokenReleaseService $tokenRelease
     * @param StepUpSession $stepUpSession
     * @param ExpiryDate $expiryDate
     */
    public function __construct(
        private readonly Session $customerSession,
        private readonly LicenceRepositoryInterface $licenceRepository,
        private readonly TokenReleaseService $tokenRelease,
        // M2MAP-260. The base package refuses to show a token until the customer
        // re-enters their password; this block sits on the account dashboard,
        // which is not behind that gate, so it has to ask for itself.
        private readonly StepUpSession $stepUpSession,
        private readonly ExpiryDate $expiryDate
    ) {
    }

    /**
     * An expiry date as every customer surface shows it ("17 Sep 2027"), through the base package's formatter.
     *
     * @param string|null $dateString
     * @return string
     */
    public function formatExpiry(?string $dateString): string
    {
        return $this->expiryDate->format($dateString);
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
            // M2MAP-269. The ACTIVE-only rule, the null-token check and the
            // degrade-to-empty-string on a decryption failure all live in the
            // base package's TokenReleaseService now, so this surface cannot
            // drift from the guest lookup or from the Luma one.
            //
            // M2MAP-260 is the argument, not the service: the gate is applied
            // BEFORE decryption rather than before display, because the
            // templates put the plaintext into the HTML and use Alpine only to
            // toggle visibility - anything decrypted here reaches view-source
            // whether or not a button is pressed.
            $result[] = [
                'licence'         => $licence,
                'decrypted_token' => $this->tokenRelease->release($licence, $verified),
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
