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

/**
 * @extends AbstractType<LlmModelSelectionData>
 */
final class LlmModelSelectionType extends AbstractType
{
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
                'choices' => self::modelChoices($models),
                'choice_translation_domain' => false,
                // Without a placeholder a saved model missing from the list would silently show the first one
                'placeholder' => 'admin.llm_model_selection.model_placeholder',
                'label' => 'admin.llm_model_selection.model',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LlmModelSelectionData::class,
            'tasks' => null,
        ]);
        $resolver->setRequired(['provider', 'models']);
        $resolver->setAllowedTypes('tasks', ['null', LlmTask::class.'[]']);
        $resolver->setAllowedTypes('provider', LlmProvider::class);
        $resolver->setAllowedTypes('models', LlmModelList::class);
    }

    /**
     * @return array<string, string>
     */
    private static function modelChoices(LlmModelList $models): array
    {
        $names = array_map(static fn (LlmModel $model): string => $model->value, iterator_to_array($models, false));
        sort($names);

        return array_combine($names, $names);
    }
}
