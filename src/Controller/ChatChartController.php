<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChartFiles;
use App\Entity\User;
use App\Repository\ChatConversationRepository;
use App\Security\FinanceRecordVoter;
use League\Flysystem\UnableToReadFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatChartController extends AbstractController
{
    #[Route('/home/chat/{conversation}/chart/{file}', name: ChartFiles::ROUTE, requirements: ['conversation' => '[0-9a-f-]{36}', 'file' => '[0-9a-f-]{36}\.(?:html|png|jpg)'], methods: ['GET'])]
    public function __invoke(string $conversation, string $file, #[CurrentUser] User $owner, ChatConversationRepository $conversations, ChartFiles $files): StreamedResponse
    {
        $chat = $conversations->owned($owner, $conversation);
        if (null === $chat) {
            throw $this->createNotFoundException();
        }
        $retained = false;
        foreach ($chat->charts as $chart) {
            if ($file === $chart['file']) {
                $retained = true;
            }
        }
        if (!$retained) {
            throw $this->createNotFoundException();
        }
        try {
            $stream = $files->read($conversation, $file);
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
            'Content-Type' => str_ends_with($file, '.html') ? 'text/html; charset=UTF-8' : (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg'),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => str_ends_with($file, '.html') ? ChartFiles::HTML_CSP : "default-src 'none'; sandbox",
        ]);
    }
}
