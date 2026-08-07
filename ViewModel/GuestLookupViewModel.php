<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class GuestLookupViewModel implements ArgumentInterface
{
    private const RATE_LIMIT_PREFIX = 'bluefairy_packagist_guest_';
    private const MAX_ATTEMPTS      = 5;
    private const WINDOW_SECONDS    = 3600;

    /**
     * @param LicenceRepositoryInterface $licenceRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param EncryptorInterface $encryptor
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly LicenceRepositoryInterface $licenceRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly EncryptorInterface $encryptor,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @param string $orderIncrementId
     * @param string $email
     * @param string $ip
     * @return array{success: bool, licences?: array, error?: string}
     */
    public function lookup(string $orderIncrementId, string $email, string $ip): array
    {
        if ($this->isRateLimited($ip)) {
            return ['success' => false, 'error' => 'Too many attempts. Please try again in 1 hour.'];
        }

        $this->recordAttempt($ip);

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $orderIncrementId)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();
        if ($orders === []) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        $order = reset($orders);
        if (strtolower((string) $order->getCustomerEmail()) !== strtolower($email)) {
            return ['success' => false, 'error' => 'Email address does not match this order.'];
        }

        $licences = $this->licenceRepository->getByOrderId((int) $order->getId());
        if ($licences === []) {
            return ['success' => false, 'error' => 'No extensions found for this order.'];
        }

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
            $result[] = ['licence' => $licence, 'decrypted_token' => $decryptedToken];
        }

        return ['success' => true, 'licences' => $result];
    }

    private function isRateLimited(string $ip): bool
    {
        $key = self::RATE_LIMIT_PREFIX . md5($ip);
        $attempts = (int) ($this->cache->load($key) ?: 0);
        return $attempts >= self::MAX_ATTEMPTS;
    }

    private function recordAttempt(string $ip): void
    {
        $key = self::RATE_LIMIT_PREFIX . md5($ip);
        $attempts = (int) ($this->cache->load($key) ?: 0);
        $this->cache->save((string) ($attempts + 1), $key, [], self::WINDOW_SECONDS);
    }
}
