<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\StatementRepository;
use App\Security\FinanceRecordVoter;
use App\Storage\StatementStorage;
use League\Flysystem\UnableToReadFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class StatementDownloadController extends AbstractController
{
    #[Route('/home/statement/{id}/download', name: 'app_statement_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(int $id, StatementRepository $statements, StatementStorage $storage): StreamedResponse
    {
        $owner = $this->getUser();
        if (!$owner instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $statement = $statements->findForUser($owner, $id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(FinanceRecordVoter::ACCESS, $statement);
        try {
            $stream = $storage->read($owner, $statement);
        } catch (UnableToReadFile|\InvalidArgumentException) {
            throw $this->createNotFoundException('Statement file is unavailable.');
        }
        // Original names are display metadata, never paths or trusted header values.
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $statement->originalFilename)));
        if (null === $filename || '' === trim($filename, '.')) {
            $filename = 'statement';
        }

        return new StreamedResponse(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
