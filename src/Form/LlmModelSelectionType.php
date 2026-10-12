<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmTask;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
                'choice_label' => LlmProviderKeyType::providerLabel(...),
                'label' => 'admin.llm_model_selection.provider',
            ])
            ->add('model', TextType::class, [
                'label' => 'admin.llm_model_selection.model',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LlmModelSelectionData::class,
            'tasks' => null,
        ]);
        $resolver->setAllowedTypes('tasks', ['null', LlmTask::class.'[]']);
    }
}
