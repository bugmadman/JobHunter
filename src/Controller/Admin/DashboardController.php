<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle($this->translator->trans('app.name'));
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addAssetMapperEntry('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('admin.menu.dashboard', 'fa fa-gauge');
        yield MenuItem::linkTo(LlmProviderKeyCrudController::class, 'admin.menu.llm_provider_keys', 'fa fa-key');
        yield MenuItem::linkTo(
            LlmModelSelectionCrudController::class,
            'admin.menu.llm_model_selections',
            'fa fa-microchip',
        );
        yield MenuItem::linkToRoute('admin.menu.api_key', 'fa fa-plug', 'admin_api_key_index');
        yield MenuItem::linkTo(PromptCrudController::class, 'admin.menu.prompts', 'fa fa-pen');
        yield MenuItem::linkTo(
            ScanInstructionCrudController::class,
            'admin.menu.scan_instructions',
            'fa fa-magnifying-glass',
        );
        yield MenuItem::linkToRoute('admin.menu.back_to_app', 'fa fa-arrow-left', 'app_home');
    }
}
