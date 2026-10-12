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
use App\Entity\JobPosting;
use App\Message\ProcessJobPosting;
use App\Repository\PlatformRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
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
        private MessageBusInterface $bus,
        private EntityManagerInterface $entityManager,
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

        // One transaction only because the Doctrine transport shares the connection: a posting is never left
        // saved without its message. Another transport (or connection) silently drops this guarantee.
        $posting = $this->entityManager->wrapInTransaction(function () use ($platform, $details): JobPosting {
            $posting = $this->postings->create($platform, $details);
            $id = $posting->getId() ?? throw new \LogicException('A saved job posting has an id.');
            $this->bus->dispatch(new ProcessJobPosting($id));

            return $posting;
        });

        return ReceivedJobPosting::fromJobPosting($posting);
    }

    private function violation(string $field, string $message): ConstraintViolation
    {
        return new ConstraintViolation($message, null, [], null, $field, null);
    }
}
