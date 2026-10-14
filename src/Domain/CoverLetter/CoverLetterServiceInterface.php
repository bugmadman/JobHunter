<?php

declare(strict_types=1);

namespace App\Domain\CoverLetter;

use App\Entity\CoverLetter;
use App\Entity\JobPosting;
use App\Infrastructure\Llm\LlmRequestFailed;

interface CoverLetterServiceInterface
{
    /**
     * Creates a pending cover letter version for the job posting and saves it. The caller dispatches the generation
     * for the returned letter.
     *
     * @throws CoverLetterGenerationInProgress the posting's latest letter is still pending; nothing is saved
     */
    public function requestGeneration(JobPosting $posting): CoverLetter;

    /**
     * Asks the LLM for a letter from the latest analysed CV profile, the job posting and its platform's prompt (if
     * there is one), and saves it as the letter's text, marked done.
     *
     * A failed LLM request leaves the generation pending, so the call can be retried. Any other failure (no analysed
     * CV profile, an empty response, the LLM settings missing) marks the generation failed and saves it first.
     *
     * @throws CoverLetterGenerationAlreadyFinished the generation is not pending; nothing is called or saved
     * @throws CoverLetterGenerationFailed
     * @throws LlmRequestFailed
     */
    public function generate(CoverLetter $coverLetter): void;

    /**
     * Saves the owner's edited text of a generated letter.
     *
     * @throws CoverLetterNotGenerated the letter is not generated; nothing is saved
     */
    public function edit(CoverLetter $coverLetter, CoverLetterText $text): void;

    /**
     * Marks a still pending generation failed once its retries are used up; a finished one is left as it is.
     */
    public function giveUpGeneration(CoverLetter $coverLetter): void;
}
