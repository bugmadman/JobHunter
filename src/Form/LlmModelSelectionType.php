<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelList;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmTask;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<LlmModelSelectionData>
 */
final class LlmModelSelectionType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function taskLabel(LlmTask $task): string
    {
        return 'llm.task.'.$task->value;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<LlmTask>|null $tasks */
        $tasks = $options['tasks'];
        /** @var LlmProvider $provider */
        $provider = $options['provider'];
        /** @var LlmModelList $models */
        $models = $options['models'];
        /** @var LlmModel|null $currentModel */
        $currentModel = $options['current_model'];

        if (null !== $tasks) {
            $builder->add('task', EnumType::class, [
                'class' => LlmTask::class,
                'choices' => $tasks,
                'choice_label' => self::taskLabel(...),
                'label' => 'admin.llm_model_selection.task',
            ]);
        }

        $builder
            ->add('provider', EnumType::class, [
                'class' => LlmProvider::class,
                // Only this provider: the model choices are its list, so another provider can't be paired with them
                'choices' => [$provider],
                'choice_label' => LlmProviderKeyType::providerLabel(...),
                'label' => 'admin.llm_model_selection.provider',
            ])
            ->add('model', ChoiceType::class, [
                'choices' => $this->modelChoices($models, $currentModel),
                'choice_translation_domain' => false,
                'placeholder' => 'admin.llm_model_selection.model_placeholder',
                'label' => 'admin.llm_model_selection.model',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LlmModelSelectionData::class,
            'tasks' => null,
            'current_model' => null,
        ]);
        $resolver->setRequired(['provider', 'models']);
        $resolver->setAllowedTypes('tasks', ['null', LlmTask::class.'[]']);
        $resolver->setAllowedTypes('provider', LlmProvider::class);
        $resolver->setAllowedTypes('models', LlmModelList::class);
        $resolver->setAllowedTypes('current_model', ['null', LlmModel::class]);
    }

    /**
     * @return array<string, string>
     */
    private function modelChoices(LlmModelList $models, ?LlmModel $currentModel): array
    {
        $names = array_map(static fn (LlmModel $model): string => $model->value, iterator_to_array($models, false));
        sort($names);
        $choices = array_combine($names, $names);

        if (null === $currentModel || $models->contains($currentModel)) {
            return $choices;
        }

        // A retired model stays a valid choice, so submitting it unchanged reaches LlmService and gets its refusal
        $label = $this->translator->trans('admin.llm_model_selection.model_unavailable', [
            '%model%' => $currentModel->value,
        ]);

        return [$label => $currentModel->value] + $choices;
    }
}
