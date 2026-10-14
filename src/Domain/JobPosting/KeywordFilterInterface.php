<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Domain\Skill\SkillName;

interface KeywordFilterInterface
{
    /**
     * The skills named in the posting's title or description, in input order, each once. Empty means the posting
     * mentions none of them.
     *
     * @param list<SkillName> $skills
     *
     * @return list<SkillName>
     */
    public function matchingSkills(JobPostingDetails $details, array $skills): array;
}
