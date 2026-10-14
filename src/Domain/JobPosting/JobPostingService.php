<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Domain\Skill\SkillName;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Entity\Skill;
use App\Repository\CvProfileRepositoryInterface;
use App\Repository\JobPostingRepositoryInterface;
use Psr\Clock\ClockInterface;

final readonly class JobPostingService implements JobPostingServiceInterface
{
    private const string NO_ANALYSED_CV = 'No analysed CV to take the skills from';
    private const string NO_CV_SKILLS = 'The analysed CV has no skills';
    private const string NO_MATCHING_SKILLS = 'None of the CV skills appear in the posting';

    public function __construct(
        private JobPostingRepositoryInterface $postings,
        private CvProfileRepositoryInterface $cvProfiles,
        private KeywordFilterInterface $keywordFilter,
        private ClockInterface $clock,
    ) {
    }

    public function create(Platform $platform, JobPostingDetails $details): JobPosting
    {
        $posting = new JobPosting($platform, $details, $this->clock->now());
        $this->postings->save($posting);

        return $posting;
    }

    public function filter(JobPosting $posting): void
    {
        if (JobPostingStatus::Raw !== $posting->getStatus()) {
            throw JobPostingAlreadyFiltered::withStatus($posting->getStatus());
        }

        $cvProfile = $this->cvProfiles->findLatestAnalysed();
        $skills = array_map(
            static fn (Skill $skill): SkillName => SkillName::fromString($skill->getName()),
            $cvProfile?->getSkills() ?? [],
        );
        $now = $this->clock->now();

        if (null === $cvProfile) {
            $posting->markFailed(StatusReason::fromString(self::NO_ANALYSED_CV), $now);
        } elseif ([] === $skills) {
            $posting->markFailed(StatusReason::fromString(self::NO_CV_SKILLS), $now);
        } elseif ([] === $this->keywordFilter->matchingSkills($posting->getDetails(), $skills)) {
            $posting->markRejected(StatusReason::fromString(self::NO_MATCHING_SKILLS), $now);
        } else {
            $posting->markFiltered($now);
        }

        $this->postings->save($posting);
    }
}
