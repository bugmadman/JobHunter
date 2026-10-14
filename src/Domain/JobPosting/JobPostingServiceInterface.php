<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Infrastructure\Llm\LlmRequestFailed;

interface JobPostingServiceInterface
{
    /**
     * Saves a new posting from the platform as raw, received now.
     */
    public function create(Platform $platform, JobPostingDetails $details): JobPosting;

    /**
     * Runs a raw posting through the keyword filter against the skills of the latest analysed CV: filtered when it
     * names at least one of them, rejected when it names none, failed for good when there are no CV skills to match.
     *
     * @throws JobPostingAlreadyFiltered when the posting is no longer raw (a redelivered message)
     */
    public function filter(JobPosting $posting): void;

    /**
     * Asks the LLM whether a filtered posting fits, with its platform's prompt as the instructions, and saves the
     * posting evaluated with the verdict and its reason.
     *
     * A failed LLM request leaves the posting filtered, so the call can be retried. Any other failure (the platform
     * has no prompt text, the LLM settings are missing, the response is invalid) marks the posting failed for good,
     * with the cause as the reason, and saves it; nothing is thrown for it.
     *
     * @throws JobPostingNotFiltered the posting is not filtered (raw, or a redelivered message); nothing is saved
     * @throws LlmRequestFailed
     */
    public function evaluate(JobPosting $posting): void;
}
