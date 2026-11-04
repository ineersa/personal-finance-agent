<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChartImages;
use App\Entity\ChatConversation;
use App\Entity\User;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\UnableToReadFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatChartController extends AbstractController
{
    #[Route('/home/chat/{conversation}/chart/{file}', name: ChartImages::ROUTE, requirements: ['conversation' => '[0-9a-f-]{36}', 'file' => '[0-9a-f-]{36}\.(?:png|jpg)'], methods: ['GET'])]
    public function __invoke(string $conversation, string $file, #[CurrentUser] User $owner, EntityManagerInterface $em, ChartImages $images): StreamedResponse
    {
        $chat = $em->getRepository(ChatConversation::class)->findOneBy(['id' => $conversation, 'user' => $owner]);
        if (null === $chat) {
            throw $this->createNotFoundException();
        }
        $retained = false;
        foreach ($chat->turns as $turn) {
            foreach ($turn['charts'] ?? [] as $chart) {
                if ($file === $chart['file']) {
                    $retained = true;
                }
            }
        }
        if (!$retained) {
            throw $this->createNotFoundException();
        }
        try {
            $stream = $images->read($conversation, $file);
        } catch (UnableToReadFile) {
            throw $this->createNotFoundException('Chart unavailable.');
        }

        return new StreamedResponse(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
