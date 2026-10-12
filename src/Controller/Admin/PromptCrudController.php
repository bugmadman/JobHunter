<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Prompt\EmptyPromptText;
use App\Domain\Prompt\PromptGenerationInProgress;
use App\Domain\Prompt\PromptServiceInterface;
use App\Domain\Prompt\PromptSource;
use App\Domain\Prompt\PromptText;
use App\Entity\Prompt;
use App\Form\PromptData;
use App\Form\PromptType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Only the index and edit(): a prompt comes into being with its first generation request and goes away with its
 * platform. edit() is replaced with a form bound to a DTO, as in LlmProviderKeyCrudController: the entity has no
 * setters and is changed only through PromptService.
 *
 * @extends AbstractCrudController<Prompt>
 */
final class PromptCrudController extends AbstractCrudController
{
    private const int EXCERPT_LENGTH = 100;

    public function __construct(
        private readonly PromptServiceInterface $promptService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Prompt::class;
    }

    private static function generationStatusLabel(LlmOperationStatus $status): string
    {
        return 'llm.operation_status.'.$status->value;
    }

    private static function sourceLabel(PromptSource $source): string
    {
        return 'prompt.source.'.$source->value;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.prompt.singular')
            ->setEntityLabelInPlural('admin.prompt.plural')
            ->setPageTitle(Crud::PAGE_INDEX, 'admin.prompt.index_title')
            ->setSearchFields(null);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DETAIL, Action::DELETE, Action::BATCH_DELETE)
            ->update(
                Crud::PAGE_INDEX,
                Action::EDIT,
                static fn (Action $action): Action => $action->displayIf(
                    static fn (Prompt $prompt): bool => LlmOperationStatus::Pending !== $prompt->getGenerationStatus(),
                ),
            );
    }

    public function configureFields(string $pageName): iterable
    {
        $sourceChoices = [];
        foreach (PromptSource::cases() as $source) {
            $sourceChoices[self::sourceLabel($source)] = $source->value;
        }

        $statusChoices = [];
        foreach (LlmOperationStatus::cases() as $status) {
            $statusChoices[self::generationStatusLabel($status)] = $status->value;
        }

        yield TextField::new('platform.name', 'admin.prompt.platform')->setSortable(false);
        yield ChoiceField::new('source', 'admin.prompt.source')->setChoices($sourceChoices);
        yield ChoiceField::new('generationStatus', 'admin.prompt.generation_status')->setChoices($statusChoices);
        yield TextField::new('text', 'admin.prompt.text')
            ->setMaxLength(self::EXCERPT_LENGTH)
            ->setSortable(false);
    }

    /**
     * @param AdminContext<Prompt> $context
     */
    public function edit(AdminContext $context): Response
    {
        // EasyAdmin answers 404 for an unknown entity ID before calling this action
        $prompt = $context->getEntity()->getInstance();
        \assert(null !== $prompt);

        $data = new PromptData();
        $data->text = $prompt->getText();
        $form = $this->createForm(PromptType::class, $data);
        $form->handleRequest($context->getRequest());

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->text);

            try {
                $this->promptService->edit($prompt, PromptText::fromString($data->text));
                $this->addFlash('success', 'admin.prompt.saved');

                return $this->redirect($this->indexUrl());
            } catch (EmptyPromptText) {
                $form->get('text')->addError(new FormError($this->translator->trans('admin.prompt.empty_text')));
            } catch (PromptGenerationInProgress) {
                $form->addError(new FormError($this->translator->trans('admin.prompt.generation_in_progress')));
            }
        }

        return $this->render('admin/form.html.twig', [
            'form' => $form,
            'title' => new TranslatableMessage('admin.prompt.edit_title', [
                '%platform%' => $prompt->getPlatform()->getName(),
            ]),
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
