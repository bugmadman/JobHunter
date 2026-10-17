<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Platform;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ScanInstructionData>
 */
final class ScanInstructionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Platform>|null $platforms */
        $platforms = $options['platforms'];

        if (null !== $platforms) {
            $builder->add('platform', EntityType::class, [
                'class' => Platform::class,
                'choices' => $platforms,
                'choice_label' => 'name',
                'label' => 'admin.scan_instruction.platform',
            ]);
        }

        // ScanInstructionText trims and rejects a blank text itself, so the rule lives in one place
        $builder->add('text', TextareaType::class, [
            'label' => 'admin.scan_instruction.text',
            'trim' => false,
            'attr' => ['rows' => 20],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ScanInstructionData::class,
            'platforms' => null,
        ]);
        $resolver->setAllowedTypes('platforms', ['null', Platform::class.'[]']);
    }
}
