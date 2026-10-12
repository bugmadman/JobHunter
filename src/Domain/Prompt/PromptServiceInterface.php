<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

use App\Entity\Platform;
use App\Entity\Prompt;
use App\Infrastructure\Llm\LlmRequestFailed;

interface PromptServiceInterface
{
    /**
     * Marks the platform's prompt pending for a generation from the latest analysed CV profile (creating the prompt
     * on the first request) and saves it. The caller dispatches the generation for the returned prompt.
     *
     * @throws AnalysedCvProfileNotFound
     * @throws PromptGenerationInProgress
     */
    public function requestGeneration(Platform $platform): Prompt;

    /**
     * Asks the LLM for a draft from the prompt's CV profile and saves it as the prompt's text, marked done.
     *
     * A failed LLM request leaves the generation pending, so the call can be retried. Any other failure (an empty
     * response, the LLM settings missing) marks the generation failed and saves it first; the old text stays.
     *
     * @throws PromptGenerationAlreadyFinished the generation is not pending; nothing is called or saved
     * @throws PromptGenerationFailed
     * @throws LlmRequestFailed
     */
    public function generate(Prompt $prompt): void;

    /**
     * Marks a still pending generation failed once its retries are used up; a finished one is left as it is.
     */
    public function giveUpGeneration(Prompt $prompt): void;

    /**
     * Replaces the prompt's text with a hand-written one, marked edited, and saves it.
     *
     * @throws PromptGenerationInProgress nothing is saved
     */
    public function edit(Prompt $prompt, PromptText $text): void;
}
