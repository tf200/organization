<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCA\Organization\Service\ContractService;
use OCA\Organization\Service\ContractSigningService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ContractController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ContractService $contractService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private ContractSigningService $contractSigningService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    public function index(int $organizationId): DataResponse
    {
        $this->requireAdmin();
        try {
            return new DataResponse(['contracts' => $this->contractService->list($organizationId)]);
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        }
    }

    #[NoCSRFRequired]
    public function create(int $organizationId, string $displayName = ''): DataResponse
    {
        $userId = $this->requireAdmin();
        $upload = $this->request->getUploadedFile('file');
        if (!is_array($upload)) {
            throw new OCSBadRequestException('No PDF file uploaded');
        }
        try {
            return new DataResponse(['contract' => $this->contractService->create($organizationId, $upload, $displayName, $userId)], 201);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('create', $organizationId, null, $e);
            throw new OCSException('Could not upload the contract');
        }
    }

    #[NoCSRFRequired]
    public function update(int $organizationId, int $contractId, string $displayName): DataResponse
    {
        $this->requireAdmin();
        try {
            return new DataResponse(['contract' => $this->contractService->rename($organizationId, $contractId, $displayName)]);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        }
    }

    #[NoCSRFRequired]
    public function replace(int $organizationId, int $contractId): DataResponse
    {
        $userId = $this->requireAdmin();
        $upload = $this->request->getUploadedFile('file');
        if (!is_array($upload)) {
            throw new OCSBadRequestException('No PDF file uploaded');
        }
        try {
            return new DataResponse(['contract' => $this->contractService->replace($organizationId, $contractId, $upload, $userId)]);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('replace', $organizationId, $contractId, $e);
            throw new OCSException('Could not replace the contract file');
        }
    }

    #[NoCSRFRequired]
    public function destroy(int $organizationId, int $contractId): DataResponse
    {
        $this->requireAdmin();
        try {
            $this->contractService->delete($organizationId, $contractId);
            return new DataResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('delete', $organizationId, $contractId, $e);
            throw new OCSException('Could not delete the contract');
        }
    }

    #[NoCSRFRequired]
    public function download(int $organizationId, int $contractId): StreamResponse|NotFoundResponse
    {
        try {
            $this->requireAdmin();
            $result = $this->contractService->getFile($organizationId, $contractId);
            $contract = $result['contract'];
            $stream = $result['file']->read();
            if ($stream === false) {
                return new NotFoundResponse();
            }
            $response = new StreamResponse($stream);
            $filename = str_replace(['"', "\r", "\n"], '', $contract->getOriginalFilename());
            $response->addHeader('Content-Type', 'application/pdf');
            $response->addHeader('Content-Length', (string) $contract->getFileSize());
            $response->addHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
            return $response;
        } catch (\Throwable) {
            return new NotFoundResponse();
        }
    }

    #[NoCSRFRequired]
    public function signatureRequests(int $organizationId, int $contractId): DataResponse
    {
        $this->requireAdmin();
        try {
            return new DataResponse(['requests' => $this->contractSigningService->list($organizationId, $contractId)]);
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        }
    }

    #[NoCSRFRequired]
    public function createSignatureRequest(int $organizationId, int $contractId, array $signers): DataResponse
    {
        $user = $this->requireAdminUser();
        try {
            return new DataResponse($this->contractSigningService->createDraft($organizationId, $contractId, $signers, $user), 201);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('create-signature-request', $organizationId, $contractId, $e);
            throw new OCSException('Could not create the LibreSign draft');
        }
    }

    #[NoCSRFRequired]
    public function sendSignatureRequest(int $organizationId, int $contractId, int $requestId): DataResponse
    {
        $user = $this->requireAdminUser();
        try {
            return new DataResponse(['request' => $this->contractSigningService->send($organizationId, $contractId, $requestId, $user)]);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('send-signature-request', $organizationId, $contractId, $e);
            throw new OCSException('Could not send signature invitations');
        }
    }

    #[NoCSRFRequired]
    public function signaturePlacement(int $organizationId, int $contractId, int $requestId): DataResponse
    {
        $user = $this->requireAdminUser();
        try {
            return new DataResponse(['placementUrl' => $this->contractSigningService->placementUrlFor($organizationId, $contractId, $requestId, $user)]);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        }
    }

    #[NoCSRFRequired]
    public function signaturePlacementData(int $organizationId, int $contractId, int $requestId): DataResponse
    {
        $user = $this->requireAdminUser();
        try {
            return new DataResponse($this->contractSigningService->getPlacementData($organizationId, $contractId, $requestId, $user));
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('load-signature-placement', $organizationId, $contractId, $e);
            throw new OCSException('Could not load signature placement data');
        }
    }

    #[NoCSRFRequired]
    public function saveSignaturePlacement(int $organizationId, int $contractId, int $requestId, array $elements): DataResponse
    {
        $user = $this->requireAdminUser();
        try {
            return new DataResponse(['elements' => $this->contractSigningService->savePlacementElements($organizationId, $contractId, $requestId, $user, $elements)]);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw new OCSNotFoundException($e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('save-signature-placement', $organizationId, $contractId, $e);
            throw new OCSException('Could not save signature placement');
        }
    }

    #[NoCSRFRequired]
    public function signaturePlacementPdf(int $organizationId, int $contractId, int $requestId): StreamResponse|NotFoundResponse
    {
        try {
            $user = $this->requireAdminUser();
            $content = $this->contractSigningService->getPlacementPdf($organizationId, $contractId, $requestId, $user);
            $stream = fopen('php://temp', 'r+');
            if ($stream === false) {
                return new NotFoundResponse();
            }
            fwrite($stream, $content);
            rewind($stream);
            $response = new StreamResponse($stream);
            $response->addHeader('Content-Type', 'application/pdf');
            $response->addHeader('Content-Length', (string)strlen($content));
            $response->addHeader('Content-Disposition', sprintf('inline; filename="contract-placement-%d.pdf"', $requestId));
            return $response;
        } catch (\Throwable $e) {
            $this->logFailure('download-signature-placement-pdf', $organizationId, $contractId, $e);
            return new NotFoundResponse();
        }
    }

    #[NoCSRFRequired]
    public function signedDownload(int $organizationId, int $contractId, int $requestId): StreamResponse|NotFoundResponse
    {
        try {
            $this->requireAdmin();
            $file = $this->contractSigningService->getSignedFile($organizationId, $contractId, $requestId);
            $stream = $file->read();
            if ($stream === false) {
                return new NotFoundResponse();
            }
            $response = new StreamResponse($stream);
            $response->addHeader('Content-Type', 'application/pdf');
            $response->addHeader('Content-Length', (string) $file->getSize());
            $response->addHeader('Content-Disposition', sprintf('attachment; filename="signed-contract-%d.pdf"', $requestId));
            return $response;
        } catch (\Throwable) {
            return new NotFoundResponse();
        }
    }

    private function requireAdmin(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            throw new OCSForbiddenException('Global administrator access required');
        }
        return $user->getUID();
    }

    private function requireAdminUser(): \OCP\IUser
    {
        $this->requireAdmin();
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }
        return $user;
    }

    private function logFailure(string $action, int $organizationId, ?int $contractId, \Throwable $e): void
    {
        $this->logger->error('Contract operation failed', [
            'action' => $action,
            'organizationId' => $organizationId,
            'contractId' => $contractId,
            'exception' => $e,
        ]);
    }
}
