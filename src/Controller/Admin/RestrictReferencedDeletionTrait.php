<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityRemoveException;
use Symfony\Component\HttpFoundation\Response;

trait RestrictReferencedDeletionTrait
{
    public function delete(AdminContext $context): KeyValueStore|Response
    {
        try {
            return parent::delete($context);
        } catch (EntityRemoveException $exception) {
            if (!$exception->getPrevious() instanceof ForeignKeyConstraintViolationException) {
                throw $exception;
            }
            $this->addFlash('warning', 'This record is still referenced and was not deleted. No related financial records were removed.');

            return $this->redirectToRoute('admin');
        }
    }
}
