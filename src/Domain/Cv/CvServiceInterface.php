<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Entity\CvProfile;
use App\Infrastructure\Llm\LlmRequestFailed;

interface CvServiceInterface
{
    /**
     * Every upload is a new profile: the latest one is the current CV.
     *
     * @throws InvalidCvFile
     */
    public function upload(CvUpload $upload): CvProfile;

    /**
     * Sends the stored PDF to the LLM and saves the analysis with the profile marked done.
     *
     * A failed LLM request leaves the profile pending, so the call can be retried. Any other failure (an invalid
     * response, the LLM settings missing, the stored file unreadable) marks the profile failed and saves it first.
     *
     * @throws CvAnalysisAlreadyFinished the profile is not pending; nothing is called or saved
     * @throws CvAnalysisFailed
     * @throws LlmRequestFailed
     */
    public function analyze(CvProfile $cvProfile): void;
}
