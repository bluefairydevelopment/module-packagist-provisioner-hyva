<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use BlueFairy\PackagistProvisionerHyva\ViewModel\OrderLicenceViewModel;
use BlueFairy\PackagistProvisioner\Model\Config;
use BlueFairy\PackagistProvisioner\Model\TokenReleaseService;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Backs the credentials block on the order view. This package had no tests at
 * all; everything ever asserted about it was asserted through a browser.
 */
class OrderLicenceViewModelTest extends TestCase
{
    /** @var LicenceRepositoryInterface&MockObject */
    private $licenceRepository;
    /** @var EncryptorInterface&MockObject */
    private $encryptor;
    /** @var StepUpSession&MockObject */
    private $stepUpSession;
    /** @var Registry&MockObject */
    private $registry;
    /** @var Config&MockObject */
    private $config;
    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    private OrderLicenceViewModel $viewModel;

    protected function setUp(): void
    {
        $this->licenceRepository = $this->createMock(LicenceRepositoryInterface::class);
        $this->encryptor         = $this->createMock(EncryptorInterface::class);
        $this->stepUpSession     = $this->createMock(StepUpSession::class);
        $this->registry          = $this->createMock(Registry::class);

        // Verified by default - see the note in LicenceListViewModelTest.
        $this->stepUpSession->method('isVerified')->willReturn(true);

        // M2MAP-639. The store's Customer Support address, from Magento's own config.
        $this->config = $this->createMock(Config::class);
        $this->config->method('getSupportEmail')->willReturn('help@merchant.example');
        $store = $this->createMock(\Magento\Store\Api\Data\StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->viewModel = new OrderLicenceViewModel(
            $this->licenceRepository,
            // M2MAP-269. The REAL release service over a mocked encryptor.
            // These tests assert the ACTIVE-only rule and the degrade-to-empty
            // behaviour, which now live in the service - mocking it would leave
            // them asserting nothing.
            new TokenReleaseService($this->encryptor),
            $this->stepUpSession,
            $this->registry,
            $this->config,
            $this->storeManager
        );
    }

    public function testAnOrderWithNoLicencesYieldsNothing(): void
    {
        $this->licenceRepository->method('getByOrderId')->willReturn([]);

        self::assertSame([], $this->viewModel->getLicencesForOrder(7));
    }

    public function testAnActiveLicenceCarriesItsDecryptedToken(): void
    {
        $this->licenceRepository->method('getByOrderId')
            ->willReturn([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);
        $this->encryptor->method('decrypt')->with('ciphertext')->willReturn('PLAINTEXT');

        $result = $this->viewModel->getLicencesForOrder(7);

        self::assertCount(1, $result);
        self::assertSame('PLAINTEXT', $result[0]['decrypted_token']);
    }

    /**
     * The security boundary: knowing an order id must not yield a working
     * credential for a licence that has been revoked or has expired.
     *
     * @dataProvider nonActiveStatuses
     */
    public function testOnlyAnActiveLicenceYieldsAToken(string $status): void
    {
        $this->licenceRepository->method('getByOrderId')
            ->willReturn([$this->licence($status, 'ciphertext')]);
        $this->encryptor->expects(self::never())->method('decrypt');

        $result = $this->viewModel->getLicencesForOrder(7);

        self::assertSame('', $result[0]['decrypted_token']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonActiveStatuses(): array
    {
        return [
            'revoked' => [LicenceInterface::STATUS_REVOKED],
            'expired' => [LicenceInterface::STATUS_EXPIRED],
            'failed'  => [LicenceInterface::STATUS_FAILED],
            'pending' => [LicenceInterface::STATUS_PENDING],
        ];
    }

    /**
     * A key rotation leaves tokens that cannot be decrypted. The block must
     * still render - an exception here would take the whole order view down.
     */
    public function testAnUndecryptableTokenDegradesToEmptyRatherThanThrowing(): void
    {
        $this->licenceRepository->method('getByOrderId')
            ->willReturn([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);
        $this->encryptor->method('decrypt')->willThrowException(new \Exception('bad key'));

        $result = $this->viewModel->getLicencesForOrder(7);

        self::assertSame('', $result[0]['decrypted_token']);
    }

    /**
     * These strings are shown to a paying customer whose purchase has not
     * arrived, so the package name and the order reference are the whole point
     * of them - without those, support cannot act on the report.
     */
    public function testTheCustomerFacingMessagesNameThePackageAndTheOrder(): void
    {
        $licence = $this->licence(LicenceInterface::STATUS_PENDING, null);

        foreach ([$this->viewModel->getPendingMessage($licence), $this->viewModel->getFailedMessage($licence)] as $message) {
            self::assertStringContainsString('bluefairy/module-mailchimp', $message);
            self::assertStringContainsString('4242', $message);
            // M2MAP-639: the STORE's support address, never Blue Fairy's.
            self::assertStringContainsString('help@merchant.example', $message);
            self::assertStringNotContainsString('bluefairydevelopment.com', $message);
        }
    }

    /**
     * M2MAP-260. The order view was the SECOND ungated route to the same value:
     * knowing an order id and holding a live session was enough. The base
     * package places no block on this handle at all, so this surface existed
     * only in the Hyvä companion and only without the gate.
     */
    public function testAnUnverifiedCustomerIsNeverHandedAToken(): void
    {
        $viewModel = $this->viewModelWithStepUp(false);
        $this->licenceRepository->method('getByOrderId')
            ->willReturn([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);

        $this->encryptor->expects(self::never())->method('decrypt');

        $result = $viewModel->getLicencesForOrder(7);

        self::assertCount(1, $result);
        self::assertSame('', $result[0]['decrypted_token']);
        self::assertFalse($viewModel->isStepUpVerified());
    }

    /**
     * The positive control: the empty string above is the gate, not a broken
     * view model.
     */
    public function testAVerifiedCustomerIsHandedThePlaintext(): void
    {
        $viewModel = $this->viewModelWithStepUp(true);
        $this->licenceRepository->method('getByOrderId')
            ->willReturn([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);
        $this->encryptor->expects(self::once())->method('decrypt')->with('ciphertext')->willReturn('PLAINTEXT');

        self::assertSame('PLAINTEXT', $viewModel->getLicencesForOrder(7)[0]['decrypted_token']);
    }

    private function viewModelWithStepUp(bool $verified): OrderLicenceViewModel
    {
        $stepUp = $this->createMock(StepUpSession::class);
        $stepUp->method('isVerified')->willReturn($verified);

        return new OrderLicenceViewModel(
            $this->licenceRepository,
            // M2MAP-269. The REAL release service over a mocked encryptor.
            // These tests assert the ACTIVE-only rule and the degrade-to-empty
            // behaviour, which now live in the service - mocking it would leave
            // them asserting nothing.
            new TokenReleaseService($this->encryptor),
            $stepUp,
            $this->registry,
            $this->config,
            $this->storeManager
        );
    }

    /**
     * M2MAP-624. The block read `$block->getData('order_id')`, which nothing set, so
     * it had never rendered. The order now comes from the registry entry Magento's
     * own order-view blocks read - and from nowhere else.
     */
    public function testTheCurrentOrderIdComesFromTheRegistry(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(183);
        $this->registry->method('registry')->with('current_order')->willReturn($order);

        self::assertSame(183, $this->viewModel->getCurrentOrderId());
    }

    public function testOffAnOrderViewPageTheCurrentOrderIdIsZero(): void
    {
        $this->registry->method('registry')->willReturn(null);
        self::assertSame(0, $this->viewModel->getCurrentOrderId());
    }

    /**
     * The template must ask the view model, never the request: an order id taken
     * from a request parameter would render another customer's credentials.
     * Control: the same predicate finds getCurrentOrderId(), which the template
     * must call.
     */
    public function testTheTemplateTakesTheOrderFromTheViewModelAndNotFromTheRequest(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/view/frontend/templates/order/credentials.phtml');
        self::assertStringContainsString('getCurrentOrderId()', $template, 'CONTROL: the template no longer asks the view model for the order');
        foreach (["getData('order_id')", 'getParam(', 'getRequest()'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $template, 'the order-view template reads the order from ' . $forbidden);
        }
    }

    private function licence(string $status, ?string $token): LicenceInterface
    {
        $licence = $this->createMock(LicenceInterface::class);
        $licence->method('getStatus')->willReturn($status);
        $licence->method('getToken')->willReturn($token);
        $licence->method('getPackageName')->willReturn('bluefairy/module-mailchimp');
        $licence->method('getOrderId')->willReturn(4242);

        return $licence;
    }
}
