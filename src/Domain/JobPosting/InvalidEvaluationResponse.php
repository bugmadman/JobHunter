<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final class InvalidEvaluationResponse extends \DomainException
{
    /**
     * @param non-empty-list<string> $errors
     */
    private function __construct(
        public readonly array $errors,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The LLM evaluation response is invalid: '.implode('; ', $errors), 0, $previous);
    }

    /**
     * @param non-empty-list<string> $errors
     */
    public static function schemaViolation(array $errors): self
    {
        return new self($errors);
    }

    public static function invalidValue(\DomainException $previous): self
    {
        return new self([$previous->getMessage()], $previous);
    }
}
