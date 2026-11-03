<?php

declare(strict_types=1);

namespace App\ChatGpt;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class CallbackPage
{
    public function __construct(private Connection $connection, private Environment $twig, #[Autowire('%env(int:CHATGPT_CALLBACK_PORT)%')] private int $port)
    {
    }

    public function respond(Request $request): Response
    {
        $status = 'failed';
        try {
            if (!$request->isMethod('GET') || '127.0.0.1:'.$this->port !== $request->headers->get('host')) {
                throw new \RuntimeException('Invalid local callback address.');
            }
            $status = $this->connection->complete($request->query->all());
        } catch (\Throwable) {
            // Never send callback parameters or provider exception details to logs or the page.
        }

        return new Response($this->twig->render('chatgpt/callback.html.twig', ['status' => $status, 'finance_url' => $this->connection->returnUrl()]), 200, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
