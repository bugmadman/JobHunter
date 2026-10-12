<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\JobPostingIntake;
use App\ApiResource\ReceivedJobPosting;
use App\Domain\JobPosting\InvalidJobPostingDetails;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingServiceInterface;
use App\Repository\PlatformRepositoryInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @implements ProcessorInterface<JobPostingIntake, ReceivedJobPosting>
 */
final readonly class JobPostingIntakeProcessor implements ProcessorInterface
{
    public function __construct(
        private PlatformRepositoryInterface $platforms,
        private JobPostingServiceInterface $postings,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws ValidationException an unknown platform or details the constraints let through; nothing is saved
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ReceivedJobPosting {
        $violations = new ConstraintViolationList();

        $platform = $this->platforms->findByName($data->platform);
        if (null === $platform) {
            $violations->add($this->violation(
                'platform',
                $this->translator->trans('api.job_posting.unknown_platform', ['%platform%' => $data->platform]),
            ));
        }

        try {
            $details = JobPostingDetails::create(
                $data->title,
                $data->description,
                $data->url,
                $data->company,
                $data->location,
            );
        } catch (InvalidJobPostingDetails $exception) {
            $details = null;
            $violations->add($this->violation(
                $exception->field,
                $this->translator->trans('api.job_posting.invalid_value'),
            ));
        }

        if (null === $platform || null === $details) {
            throw new ValidationException($violations);
        }

        return ReceivedJobPosting::fromJobPosting($this->postings->create($platform, $details));
    }

    private function violation(string $field, string $message): ConstraintViolation
    {
        return new ConstraintViolation($message, null, [], null, $field, null);
    }
}
