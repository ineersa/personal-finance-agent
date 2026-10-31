<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Admin\UserCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(AdminUrlGenerator $adminUrl): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirect($adminUrl->unsetAll()->setController(UserCrudController::class)->generateUrl());
        }

        return $this->render($this->getUser() ? 'home/index.html.twig' : 'home/landing.html.twig');
    }
}
