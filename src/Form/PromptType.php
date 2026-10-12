<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PromptData>
 */
final class PromptType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // PromptText trims and rejects a blank text itself, so the rule lives in one place
        $builder->add('text', TextareaType::class, [
            'label' => 'admin.prompt.text',
            'trim' => false,
            'attr' => ['rows' => 20],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PromptData::class]);
    }
}
