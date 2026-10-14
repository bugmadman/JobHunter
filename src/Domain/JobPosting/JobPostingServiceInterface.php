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
     * Takes a posting through the pipeline from its current status: a raw one goes through the keyword filter, and a
     * filtered one (just now, or by an earlier delivery whose LLM request failed) through the LLM evaluation. A
     * posting in a final status is left alone, so a redelivered message changes nothing.
     *
     * @throws LlmRequestFailed the posting stays filtered, so the call can be retried
     */
    public function process(JobPosting $posting): void;

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

    /**
     * Saves the owner's verdict over the LLM's; the LLM's own verdict drops the override.
     *
     * @throws JobPostingNotEvaluated the posting has no LLM verdict yet; nothing is saved
     */
    public function overrideVerdict(JobPosting $posting, JobPostingVerdict $verdict): void;

    /**
     * Saves the owner's comment on the posting; null removes it.
     */
    public function updateComment(JobPosting $posting, ?JobPostingComment $comment): void;

    /**
     * Saves whether the owner applied to the posting; applying is dated now, unless already applied.
     */
    public function updateApplied(JobPosting $posting, bool $applied): void;
}
