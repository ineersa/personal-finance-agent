<?php

declare(strict_types=1);

namespace App\Security;

use App\Controller\Admin\CategoryCrudController;
use App\Controller\Admin\SourceCrudController;
use App\Controller\Admin\StatementCrudController;
use App\Controller\Admin\TransactionCrudController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Repository\SourceRepository;
use App\Repository\StatementRepository;
use App\Repository\TransactionRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Router\EntityIdReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Gate direct records before EasyAdmin's priority-1 context hydration. */
#[AsEventListener(event: RequestEvent::class, priority: 2)]
final readonly class AdminRecordRequestGuard
{
    public function __construct(
        private Security $security,
        private SourceRepository $sources,
        private StatementRepository $statements,
        private TransactionRepository $transactions,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->getBoolean(EA::ROUTE_CREATED_BY_EASYADMIN)) {
            return;
        }
        $controller = $request->attributes->get(EA::CRUD_CONTROLLER_FQCN) ?? $request->query->get(EA::CRUD_CONTROLLER_FQCN);
        if (\in_array($controller, [UserCrudController::class, CategoryCrudController::class], true) && !$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException();
        }
        if (!\in_array($controller, [SourceCrudController::class, StatementCrudController::class, TransactionCrudController::class], true)) {
            return;
        }
        $id = EntityIdReader::fromRequest($request);
        if (null === $id) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !\is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
            throw new AccessDeniedException();
        }
        $record = match ($controller) {
            SourceCrudController::class => $this->sources->findForUser($user, (int) $id),
            StatementCrudController::class => $this->statements->findForUser($user, (int) $id),
            TransactionCrudController::class => $this->transactions->findForUser($user, (int) $id),
        };
        if (null === $record || !$this->security->isGranted(FinanceRecordVoter::ACCESS, $record)) {
            throw new AccessDeniedException();
        }
    }
}
