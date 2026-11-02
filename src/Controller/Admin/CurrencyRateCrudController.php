<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CurrencyRate;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends AbstractCrudController<CurrencyRate> */
#[IsGranted('ROLE_ADMIN')]
final class CurrencyRateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CurrencyRate::class;
    }

    public function createEntity(string $entityFqcn): CurrencyRate
    {
        return new CurrencyRate(new \DateTimeImmutable('today', new \DateTimeZone('UTC')), 'USD', '1');
    }

    /** @param AdminContext<CurrencyRate> $context */
    public function index(AdminContext $context): KeyValueStore|Response
    {
        if (!$context->getRequest()->query->has('filters')) {
            return $this->redirect($this->container->get(AdminUrlGenerator::class)->set('filters', [
                'day' => ['comparison' => '=', 'value' => (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d')],
            ])->generateUrl());
        }

        return parent::index($context);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::BATCH_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission('ROLE_ADMIN')->setEntityLabelInSingular('Currency rate')->setEntityLabelInPlural('Currency rates')->setSearchFields(['currency'])->setDefaultSort(['day' => 'DESC', 'currency' => 'ASC'])->setPaginatorPageSize(50);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('day', 'Date')->setFormTypeOption('value_type', DateType::class))
            ->add(ChoiceFilter::new('currency')->setChoices(CurrencyRate::currencyChoices())->setFormTypeOption('value_type_options.attr', []));
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('day', 'Date')->setFormat('yyyy-MM-dd')->setFormTypeOption('input', 'datetime_immutable');
        yield ChoiceField::new('currency')->setChoices(CurrencyRate::currencyChoices())->renderAsNativeWidget();
        yield TextField::new('rate', 'Rate')->setHelp('Positive decimal string; EUR itself has the implicit rate 1.');
        yield BooleanField::new('isProvisional', 'Provisional')->renderAsSwitch(false);
    }
}
