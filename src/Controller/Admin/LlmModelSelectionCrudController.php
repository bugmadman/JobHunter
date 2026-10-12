<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;
use App\Form\LlmModelSelectionData;
use App\Form\LlmModelSelectionType;
use App\Form\LlmProviderKeyType;
use App\Repository\LlmModelSelectionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * new() and edit() are replaced with forms bound to a DTO, as in LlmProviderKeyCrudController: the entity has
 * no setters and is changed only through LlmService.
 *
 * @extends AbstractCrudController<LlmModelSelection>
 */
final class LlmModelSelectionCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly LlmServiceInterface $llmService,
        private readonly LlmModelSelectionRepositoryInterface $modelSelections,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return LlmModelSelection::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.llm_model_selection.singular')
            ->setEntityLabelInPlural('admin.llm_model_selection.plural')
            ->setPageTitle(Crud::PAGE_INDEX, 'admin.llm_model_selection.index_title')
            ->setSearchFields(null);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DETAIL, Action::BATCH_DELETE)
            ->update(
                Crud::PAGE_INDEX,
                Action::NEW,
                fn (Action $action): Action => $action->displayIf(fn (): bool => [] !== $this->tasksWithoutModel()),
            );
    }

    public function configureFields(string $pageName): iterable
    {
        $taskChoices = [];
        foreach (LlmTask::cases() as $task) {
            $taskChoices[LlmModelSelectionType::taskLabel($task)] = $task->value;
        }

        $providerChoices = [];
        foreach (LlmProvider::cases() as $provider) {
            $providerChoices[LlmProviderKeyType::providerLabel($provider)] = $provider->value;
        }

        yield ChoiceField::new('task', 'admin.llm_model_selection.task')->setChoices($taskChoices);
        yield ChoiceField::new('provider', 'admin.llm_model_selection.provider')->setChoices($providerChoices);
        // The VO's own property: TextField casts the value to a string and fails on the VO itself
        yield TextField::new('model.value', 'admin.llm_model_selection.model')->setSortable(false);
    }

    /**
     * @param AdminContext<LlmModelSelection> $context
     */
    public function new(AdminContext $context): Response
    {
        $data = new LlmModelSelectionData();
        $form = $this->createForm(LlmModelSelectionType::class, $data, ['tasks' => $this->tasksWithoutModel()]);
        $form->handleRequest($context->getRequest());

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->task);
            $this->select($data->task, $data);

            return $this->redirectToIndexAfterSave();
        }

        return $this->renderForm($form, new TranslatableMessage('admin.llm_model_selection.new_title'));
    }

    /**
     * @param AdminContext<LlmModelSelection> $context
     */
    public function edit(AdminContext $context): Response
    {
        // EasyAdmin answers 404 for an unknown entity ID before calling this action
        $selection = $context->getEntity()->getInstance();
        \assert(null !== $selection);

        $data = new LlmModelSelectionData();
        $data->task = $selection->getTask();
        $data->provider = $selection->getProvider();
        $data->model = $selection->getModel()->value;
        $form = $this->createForm(LlmModelSelectionType::class, $data);
        $form->handleRequest($context->getRequest());

        if ($form->isSubmitted() && $form->isValid()) {
            $this->select($selection->getTask(), $data);

            return $this->redirectToIndexAfterSave();
        }

        return $this->renderForm($form, new TranslatableMessage('admin.llm_model_selection.edit_title', [
            '%task%' => new TranslatableMessage(LlmModelSelectionType::taskLabel($selection->getTask())),
        ]));
    }

    /**
     * @param LlmModelSelection $entityInstance
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->llmService->deleteModelSelection($entityInstance->getTask());
    }

    private function select(LlmTask $task, LlmModelSelectionData $data): void
    {
        \assert(null !== $data->provider && null !== $data->model);
        $this->llmService->selectModel($task, $data->provider, LlmModel::fromString($data->model));
    }

    /**
     * @return list<LlmTask>
     */
    private function tasksWithoutModel(): array
    {
        $configured = $this->modelSelections->findConfiguredTasks();

        return array_values(array_filter(
            LlmTask::cases(),
            static fn (LlmTask $task): bool => !\in_array($task, $configured, true),
        ));
    }

    /**
     * @param FormInterface<LlmModelSelectionData> $form
     */
    private function renderForm(FormInterface $form, TranslatableMessage $title): Response
    {
        return $this->render('admin/form.html.twig', [
            'form' => $form,
            'title' => $title,
            'index_url' => $this->indexUrl(),
        ]);
    }

    private function redirectToIndexAfterSave(): Response
    {
        $this->addFlash('success', 'admin.llm_model_selection.saved');

        return $this->redirect($this->indexUrl());
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
