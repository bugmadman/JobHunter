<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\LlmProvider;
use Symfony\Component\Validator\Constraints as Assert;

final class LlmProviderKeyData
{
    #[Assert\NotBlank]
    public ?LlmProvider $provider = null;

    #[Assert\NotBlank]
    public ?ApiKey $apiKey = null;
}
