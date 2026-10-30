<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\Transaction;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->redirect($this->container->get(AdminUrlGenerator::class)->setController(SourceCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Personal finance');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)->disableLogoutLink()->setMenuItems([
            MenuItem::linkToRoute('Settings and sign out', 'fa fa-gear', 'app_settings'),
        ]);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToRoute('Chat (preview)', 'fa fa-comment', 'app_home');
        yield MenuItem::linkToCrud('Sources', 'fa fa-wallet', Source::class);
        yield MenuItem::linkToCrud('Statements (read-only)', 'fa fa-file', Statement::class);
        yield MenuItem::linkToCrud('Approved transactions', 'fa fa-list', Transaction::class);
        yield MenuItem::linkToRoute('Upload (not implemented)', 'fa fa-upload', 'app_planned', ['page' => 'upload']);
        yield MenuItem::linkToRoute('Review (not implemented)', 'fa fa-check', 'app_planned', ['page' => 'review']);
        yield MenuItem::linkToRoute('Settings', 'fa fa-gear', 'app_settings');
        yield MenuItem::section('Administration')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToCrud('Categories', 'fa fa-tags', Category::class)->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToCrud('Users', 'fa fa-users', User::class)->setPermission('ROLE_ADMIN');
    }
}
