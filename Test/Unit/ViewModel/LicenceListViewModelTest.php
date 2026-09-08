<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use BlueFairy\PackagistProvisionerHyva\ViewModel\LicenceListViewModel;
use Magento\Customer\Model\Session;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Backs the My Extensions dashboard block - the only Hyvä surface that hands a
 * customer their token, and therefore the one place where getting the customer
 * wrong would disclose someone else's credential.
 */
class LicenceListViewModelTest extends TestCase
{
    /** @var Session&MockObject */
    private $customerSession;
    /** @var LicenceRepositoryInterface&MockObject */
    private $licenceRepository;
    /** @var EncryptorInterface&MockObject */
    private $encryptor;

    private LicenceListViewModel $viewModel;

    protected function setUp(): void
    {
        $this->customerSession   = $this->createMock(Session::class);
        $this->licenceRepository = $this->createMock(LicenceRepositoryInterface::class);
        $this->encryptor         = $this->createMock(EncryptorInterface::class);

        $this->viewModel = new LicenceListViewModel(
            $this->customerSession,
            $this->licenceRepository,
            $this->encryptor
        );
    }

    /**
     * A guest has customer id 0. Passing that to getByCustomerId() would ask the
     * repository for "the licences belonging to customer zero" — the block is
     * rendered on an account page, but a session that has expired mid-request
     * reaches here too.
     */
    public function testAGuestSessionIsRefusedWithoutAskingTheRepository(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(null);
        $this->licenceRepository->expects(self::never())->method('getByCustomerId');

        self::assertSame([], $this->viewModel->getLicences());
    }

    public function testTheLicencesFetchedAreThoseOfTheSignedInCustomer(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(15506);
        $this->licenceRepository->expects(self::once())
            ->method('getByCustomerId')
            ->with(15506)
            ->willReturn([]);

        $this->viewModel->getLicences();
    }

    public function testAnActiveLicenceCarriesItsDecryptedToken(): void
    {
        $this->givenCustomerHas([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);
        $this->encryptor->method('decrypt')->with('ciphertext')->willReturn('PLAINTEXT');

        $result = $this->viewModel->getLicences();

        self::assertSame('PLAINTEXT', $result[0]['decrypted_token']);
        self::assertSame(LicenceInterface::STATUS_ACTIVE, $result[0]['licence']->getStatus());
    }

    /**
     * @dataProvider nonActiveStatuses
     */
    public function testOnlyAnActiveLicenceYieldsAToken(string $status): void
    {
        $this->givenCustomerHas([$this->licence($status, 'ciphertext')]);
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertSame('', $this->viewModel->getLicences()[0]['decrypted_token']);
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

    public function testAnUndecryptableTokenDegradesToEmptyRatherThanThrowing(): void
    {
        $this->givenCustomerHas([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);
        $this->encryptor->method('decrypt')->willThrowException(new \Exception('bad key'));

        self::assertSame('', $this->viewModel->getLicences()[0]['decrypted_token']);
    }

    /**
     * The template branches on these five, and every branch is mutually
     * exclusive. A predicate answering true for two statuses would render two
     * states of one licence at once.
     *
     * @dataProvider allStatuses
     */
    public function testExactlyOneStatusPredicateAnswersTrueForAnyStatus(string $status): void
    {
        $licence = $this->licence($status, null);

        $true = array_filter([
            'isActive'  => $this->viewModel->isActive($licence),
            'isExpired' => $this->viewModel->isExpired($licence),
            'isRevoked' => $this->viewModel->isRevoked($licence),
            'isFailed'  => $this->viewModel->isFailed($licence),
            'isPending' => $this->viewModel->isPending($licence),
        ]);

        self::assertCount(1, $true, sprintf(
            'status %s matched: %s',
            $status,
            implode(', ', array_keys($true)) ?: 'nothing'
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allStatuses(): array
    {
        return [
            'active'  => [LicenceInterface::STATUS_ACTIVE],
            'revoked' => [LicenceInterface::STATUS_REVOKED],
            'expired' => [LicenceInterface::STATUS_EXPIRED],
            'failed'  => [LicenceInterface::STATUS_FAILED],
            'pending' => [LicenceInterface::STATUS_PENDING],
        ];
    }

    /**
     * @param array<int, LicenceInterface> $licences
     */
    private function givenCustomerHas(array $licences): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(15506);
        $this->licenceRepository->method('getByCustomerId')->willReturn($licences);
    }

    private function licence(string $status, ?string $token): LicenceInterface
    {
        $licence = $this->createMock(LicenceInterface::class);
        $licence->method('getStatus')->willReturn($status);
        $licence->method('getToken')->willReturn($token);
        $licence->method('getPackageName')->willReturn('bluefairy/module-mailchimp');

        return $licence;
    }
}
