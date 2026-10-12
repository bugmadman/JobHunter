<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Entity\LlmProviderKey;
use App\Form\LlmProviderKeyData;
use App\Form\LlmProviderKeyType;
use App\Repository\LlmProviderKeyRepositoryInterface;
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
 * new() and edit() are replaced with forms bound to a DTO: the entity holds only the ciphertext and is built
 * by LlmService, so EasyAdmin's entity forms and its persistEntity()/updateEntity() are never used.
 *
 * @extends AbstractCrudController<LlmProviderKey>
 */
final class LlmProviderKeyCrudController extends AbstractCrudController
{
    private const string MASK = '••••••••';

    public function __construct(
        private readonly LlmServiceInterface $llmService,
        private readonly LlmProviderKeyRepositoryInterface $providerKeys,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return LlmProviderKey::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.llm_provider_key.singular')
            ->setEntityLabelInPlural('admin.llm_provider_key.plural')
            ->setPageTitle(Crud::PAGE_INDEX, 'admin.llm_provider_key.index_title')
            ->setSearchFields(null);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DETAIL, Action::BATCH_DELETE)
            ->update(
                Crud::PAGE_INDEX,
                Action::NEW,
                fn (Action $action): Action => $action->displayIf(fn (): bool => [] !== $this->providersWithoutKey()),
            );
    }

    public function configureFields(string $pageName): iterable
    {
        $providerChoices = [];
        foreach (LlmProvider::cases() as $provider) {
            $providerChoices[LlmProviderKeyType::providerLabel($provider)] = $provider->value;
        }

        yield ChoiceField::new('provider', 'admin.llm_provider_key.provider')->setChoices($providerChoices);
        // A preset value is never read from the entity: the ciphertext can't reach the page
        yield TextField::new('apiKey', 'admin.llm_provider_key.api_key')
            ->setValue(self::MASK)
            ->setSortable(false);
    }

    /**
     * @param AdminContext<LlmProviderKey> $context
     */
    public function new(AdminContext $context): Response
    {
        $data = new LlmProviderKeyData();
        $form = $this->createForm(LlmProviderKeyType::class, $data, ['providers' => $this->providersWithoutKey()]);
        $form->handleRequest($context->getRequest());

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->provider && null !== $data->apiKey);
            $this->llmService->saveApiKey($data->provider, $data->apiKey);

            return $this->redirectToIndexAfterSave();
        }

        return $this->renderForm($form, new TranslatableMessage('admin.llm_provider_key.new_title'));
    }

    /**
     * @param AdminContext<LlmProviderKey> $context
     */
    public function edit(AdminContext $context): Response
    {
        // EasyAdmin answers 404 for an unknown entity ID before calling this action
        $providerKey = $context->getEntity()->getInstance();
        \assert(null !== $providerKey);

        $data = new LlmProviderKeyData();
        $data->provider = $providerKey->getProvider();
        $form = $this->createForm(LlmProviderKeyType::class, $data);
        $form->handleRequest($context->getRequest());

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->apiKey);
            $this->llmService->saveApiKey($providerKey->getProvider(), $data->apiKey);

            return $this->redirectToIndexAfterSave();
        }

        return $this->renderForm($form, new TranslatableMessage('admin.llm_provider_key.edit_title', [
            '%provider%' => new TranslatableMessage(LlmProviderKeyType::providerLabel($providerKey->getProvider())),
        ]));
    }

    /**
     * @param LlmProviderKey $entityInstance
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->llmService->deleteApiKey($entityInstance->getProvider());
    }

    /**
     * @return list<LlmProvider>
     */
    private function providersWithoutKey(): array
    {
        $configured = $this->providerKeys->findConfiguredProviders();
        $withoutKey = [];
        foreach (LlmProvider::cases() as $provider) {
            if (!\in_array($provider, $configured, true)) {
                $withoutKey[] = $provider;
            }
        }

        return $withoutKey;
    }

    /**
     * @param FormInterface<LlmProviderKeyData> $form
     */
    private function renderForm(FormInterface $form, TranslatableMessage $title): Response
    {
        return $this->render('admin/llm_provider_key/form.html.twig', [
            'form' => $form,
            'title' => $title,
            'index_url' => $this->indexUrl(),
        ]);
    }

    private function redirectToIndexAfterSave(): Response
    {
        $this->addFlash('success', 'admin.llm_provider_key.saved');

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
