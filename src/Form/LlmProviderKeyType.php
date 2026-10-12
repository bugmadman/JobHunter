<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\EmptyApiKey;
use App\Domain\Llm\LlmProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LlmProviderKeyData>
 */
final class LlmProviderKeyType extends AbstractType
{
    public static function providerLabel(LlmProvider $provider): string
    {
        return 'llm.provider.'.$provider->value;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<LlmProvider>|null $providers */
        $providers = $options['providers'];

        if (null !== $providers) {
            $builder->add('provider', EnumType::class, [
                'class' => LlmProvider::class,
                'choices' => $providers,
                'choice_label' => self::providerLabel(...),
                'label' => 'admin.llm_provider_key.provider',
            ]);
        }

        // autocomplete=new-password keeps the browser from filling in the saved login password
        $builder->add('apiKey', PasswordType::class, [
            'label' => 'admin.llm_provider_key.api_key',
            'attr' => ['autocomplete' => 'new-password'],
        ]);

        // The key is never rendered back: transform() returns '' even for an existing key.
        // A blank key becomes null, so NotBlank on the DTO reports it as a form error
        $builder->get('apiKey')->addModelTransformer(new CallbackTransformer(
            static fn (): string => '',
            static function (#[\SensitiveParameter] mixed $apiKey): ?ApiKey {
                if (!\is_string($apiKey)) {
                    return null;
                }

                try {
                    return ApiKey::fromString($apiKey);
                } catch (EmptyApiKey) {
                    return null;
                }
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LlmProviderKeyData::class,
            'providers' => null,
        ]);
        $resolver->setAllowedTypes('providers', ['null', LlmProvider::class.'[]']);
    }
}
