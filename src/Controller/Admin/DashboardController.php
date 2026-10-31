<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Theme;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

#[AdminDashboard(routePath: '/home', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_chat');
        }

        return $this->redirect($this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(UserCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Personal finance')->setFaviconPath('favicon.svg')->setDefaultColorScheme('dark')
            ->renderContentMaximized()
            ->setTheme(Theme::new()->primaryColor('#ededee')->radius('0.5rem')->spacing('lg')->grays('zinc'));
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('styles/app.css')->addCssFile('styles/admin.css');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)->disableLogoutLink()->setMenuItems($this->isGranted('ROLE_ADMIN') ? [] : [
            MenuItem::linkToRoute('Settings', null, 'admin_settings'),
        ]);
    }

    public function configureMenuItems(): iterable
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            yield MenuItem::section('Administration');
            yield MenuItem::linkTo(UserCrudController::class, 'Users');
            yield MenuItem::linkTo(CategoryCrudController::class, 'Categories');

            return;
        }

        yield MenuItem::linkToRoute('Chat', null, 'admin_chat');
        yield MenuItem::section('Finance management');
        yield MenuItem::linkTo(SourceCrudController::class, 'Sources');
        yield MenuItem::linkTo(StatementCrudController::class, 'Statements');
        yield MenuItem::linkTo(TransactionCrudController::class, 'Transactions');
        yield MenuItem::linkToRoute('Settings', null, 'admin_settings');
    }
}
