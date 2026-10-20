<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\PackagistProvisionerHyva\Test\Unit\ViewModel;

use BlueFairy\PackagistProvisioner\Api\Data\LicenceInterface;
use BlueFairy\PackagistProvisioner\Api\LicenceRepositoryInterface;
use BlueFairy\PackagistProvisioner\Model\Auth\StepUpSession;
use BlueFairy\PackagistProvisionerHyva\ViewModel\LicenceListViewModel;
use Magento\Customer\Model\Session;
use BlueFairy\PackagistProvisioner\Model\TokenReleaseService;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /** @var StepUpSession&MockObject */
    private $stepUpSession;

    private LicenceListViewModel $viewModel;

    protected function setUp(): void
    {
        $this->customerSession   = $this->createMock(Session::class);
        $this->licenceRepository = $this->createMock(LicenceRepositoryInterface::class);
        $this->encryptor         = $this->createMock(EncryptorInterface::class);
        $this->stepUpSession     = $this->createMock(StepUpSession::class);

        // The default here is the VERIFIED customer, so that every case below
        // reads as a statement about status handling rather than about the gate.
        // The gate has its own cases, and they set this explicitly.
        $this->stepUpSession->method('isVerified')->willReturn(true);

        $this->viewModel = new LicenceListViewModel(
            $this->customerSession,
            $this->licenceRepository,
            // M2MAP-269. The REAL release service over a mocked encryptor.
            // These tests assert the ACTIVE-only rule and the degrade-to-empty
            // behaviour, which now live in the service - mocking it would leave
            // them asserting nothing.
            new TokenReleaseService($this->encryptor),
            $this->stepUpSession
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
    #[DataProvider('nonActiveStatuses')]
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
    #[DataProvider('allStatuses')]
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
    /**
     * M2MAP-260, the CRITICAL this gate exists for. The base package will not
     * show a token until the customer re-enters their password; this block sits
     * on the account dashboard, which is not behind that controller.
     *
     * The assertion that matters is `never decrypt` rather than `the string is
     * empty`. Both templates put the plaintext into the HTML and use Alpine
     * only to toggle its visibility, so a token that is decrypted here reaches
     * view-source whether or not anyone presses Reveal. Asserting only on the
     * returned value would still pass if the plaintext were produced and then
     * dropped somewhere else.
     */
    public function testAnUnverifiedCustomerIsNeverHandedAToken(): void
    {
        $viewModel = $this->viewModelWithStepUp(false);
        $this->customerSession->method('getCustomerId')->willReturn(15506);
        $this->licenceRepository->method('getByCustomerId')
            ->willReturn([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);

        $this->encryptor->expects(self::never())->method('decrypt');

        $result = $viewModel->getLicences();

        self::assertSame('', $result[0]['decrypted_token']);
        self::assertFalse($viewModel->isStepUpVerified());
    }

    /**
     * The licence itself is still listed - the gate hides the credential, not
     * the fact that the customer owns the extension. Without this, the cure
     * would read as "the dashboard block disappeared".
     */
    public function testAnUnverifiedCustomerStillSeesWhichExtensionsTheyOwn(): void
    {
        $viewModel = $this->viewModelWithStepUp(false);
        $this->customerSession->method('getCustomerId')->willReturn(15506);
        $this->licenceRepository->method('getByCustomerId')
            ->willReturn([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);

        $result = $viewModel->getLicences();

        self::assertCount(1, $result);
        self::assertSame('bluefairy/module-mailchimp', $result[0]['licence']->getPackageName());
    }

    /**
     * The positive control for the two cases above: with the gate satisfied the
     * token does come back, so their empty string is the gate acting and not a
     * view model that has stopped working.
     */
    public function testAVerifiedCustomerIsHandedThePlaintext(): void
    {
        $viewModel = $this->viewModelWithStepUp(true);
        $this->customerSession->method('getCustomerId')->willReturn(15506);
        $this->licenceRepository->method('getByCustomerId')
            ->willReturn([$this->licence(LicenceInterface::STATUS_ACTIVE, 'ciphertext')]);
        $this->encryptor->expects(self::once())->method('decrypt')->with('ciphertext')->willReturn('PLAINTEXT');

        self::assertSame('PLAINTEXT', $viewModel->getLicences()[0]['decrypted_token']);
        self::assertTrue($viewModel->isStepUpVerified());
    }

    private function viewModelWithStepUp(bool $verified): LicenceListViewModel
    {
        $stepUp = $this->createMock(StepUpSession::class);
        $stepUp->method('isVerified')->willReturn($verified);

        return new LicenceListViewModel(
            $this->customerSession,
            $this->licenceRepository,
            // M2MAP-269. The REAL release service over a mocked encryptor.
            // These tests assert the ACTIVE-only rule and the degrade-to-empty
            // behaviour, which now live in the service - mocking it would leave
            // them asserting nothing.
            new TokenReleaseService($this->encryptor),
            $stepUp
        );
    }

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
