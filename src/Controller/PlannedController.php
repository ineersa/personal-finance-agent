<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlannedController extends AbstractController
{
    #[Route('/planned/{page}', name: 'app_planned', requirements: ['page' => 'upload|review'], methods: ['GET'])]
    public function __invoke(string $page): Response
    {
        return $this->render('planned/index.html.twig', ['title' => 'upload' === $page ? 'Statement upload' : 'Draft review']);
    }
}
