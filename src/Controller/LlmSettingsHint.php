<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmModelNotSelected;
use App\Form\LlmModelSelectionType;
use App\Form\LlmProviderKeyType;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class LlmSettingsHint
{
    private function __construct(
        public TranslatableMessage $hint,
        public string $settingsRoute,
    ) {
    }

    public static function from(LlmModelNotSelected|ApiKeyNotSet $exception): self
    {
        if ($exception instanceof LlmModelNotSelected) {
            return new self(new TranslatableMessage(
                'llm.settings_missing.model_not_selected',
                ['%task%' => new TranslatableMessage(LlmModelSelectionType::taskLabel($exception->task))],
            ), 'admin_llm_model_selection_new');
        }

        return new self(new TranslatableMessage(
            'llm.settings_missing.api_key_not_set',
            ['%provider%' => new TranslatableMessage(LlmProviderKeyType::providerLabel($exception->provider))],
        ), 'admin_llm_provider_key_new');
    }
}
