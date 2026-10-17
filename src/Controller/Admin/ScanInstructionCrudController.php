<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\ScanInstruction\EmptyScanInstructionText;
use App\Domain\ScanInstruction\ScanInstructionServiceInterface;
use App\Domain\ScanInstruction\ScanInstructionText;
use App\Entity\Platform;
use App\Entity\ScanInstruction;
use App\Form\ScanInstructionData;
use App\Form\ScanInstructionType;
use App\Repository\ScanInstructionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * new() and edit() are replaced with forms bound to a DTO, as in LlmProviderKeyCrudController: the entity has no
 * setters and is changed only through ScanInstructionService.
 *
 * @extends AbstractCrudController<ScanInstruction>
 */
final class ScanInstructionCrudController extends AbstractCrudController
{
    private const int EXCERPT_LENGTH = 100;

    public function __construct(
        private readonly ScanInstructionServiceInterface $scanInstructionService,
        private readonly ScanInstructionRepositoryInterface $scanInstructions,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ScanInstruction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.scan_instruction.singular')
            ->setEntityLabelInPlural('admin.scan_instruction.plural')
            ->setPageTitle(Crud::PAGE_INDEX, 'admin.scan_instruction.index_title')
            ->setSearchFields(null);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DETAIL, Action::BATCH_DELETE)
            ->update(
                Crud::PAGE_INDEX,
                Action::NEW,
                fn (Action $action): Action => $action->displayIf(
                    fn (): bool => [] !== $this->scanInstructions->findPlatformsWithoutInstruction(),
                ),
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('platform.name', 'admin.scan_instruction.platform')->setSortable(false);
        yield TextField::new('text', 'admin.scan_instruction.text')
            ->setMaxLength(self::EXCERPT_LENGTH)
            ->setSortable(false);
        yield DateTimeField::new('updatedAt', 'admin.scan_instruction.updated_at');
    }

    /**
     * @param AdminContext<ScanInstruction> $context
     */
    public function new(AdminContext $context): Response
    {
        $data = new ScanInstructionData();
        $form = $this->createForm(ScanInstructionType::class, $data, [
            'platforms' => $this->scanInstructions->findPlatformsWithoutInstruction(),
        ]);
        $form->handleRequest($context->getRequest());

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->platform);

            $response = $this->save($form, $data->platform, $data);
            if (null !== $response) {
                return $response;
            }
        }

        return $this->renderForm($form, new TranslatableMessage('admin.scan_instruction.new_title'));
    }

    /**
     * @param AdminContext<ScanInstruction> $context
     */
    public function edit(AdminContext $context): Response
    {
        // EasyAdmin answers 404 for an unknown entity ID before calling this action
        $scanInstruction = $context->getEntity()->getInstance();
        \assert(null !== $scanInstruction);

        $data = new ScanInstructionData();
        $data->platform = $scanInstruction->getPlatform();
        $data->text = $scanInstruction->getText();
        $form = $this->createForm(ScanInstructionType::class, $data);
        $form->handleRequest($context->getRequest());

        if ($form->isSubmitted() && $form->isValid()) {
            $response = $this->save($form, $scanInstruction->getPlatform(), $data);
            if (null !== $response) {
                return $response;
            }
        }

        return $this->renderForm($form, new TranslatableMessage('admin.scan_instruction.edit_title', [
            '%platform%' => $scanInstruction->getPlatform()->getName(),
        ]));
    }

    /**
     * @param ScanInstruction $entityInstance
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->scanInstructionService->delete($entityInstance);
    }

    /**
     * @param FormInterface<ScanInstructionData> $form
     *
     * @return Response|null the redirect after a save, or null when the text is blank and the form shows the error
     */
    private function save(FormInterface $form, Platform $platform, ScanInstructionData $data): ?Response
    {
        \assert(null !== $data->text);

        try {
            $this->scanInstructionService->save($platform, ScanInstructionText::fromString($data->text));
        } catch (EmptyScanInstructionText) {
            $form->get('text')->addError(
                new FormError($this->translator->trans('admin.scan_instruction.empty_text')),
            );

            return null;
        }

        $this->addFlash('success', 'admin.scan_instruction.saved');

        return $this->redirect($this->indexUrl());
    }

    /**
     * @param FormInterface<ScanInstructionData> $form
     */
    private function renderForm(FormInterface $form, TranslatableMessage $title): Response
    {
        return $this->render('admin/form.html.twig', [
            'form' => $form,
            'title' => $title,
            'index_url' => $this->indexUrl(),
        ]);
    }

    private function indexUrl(): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }
}
