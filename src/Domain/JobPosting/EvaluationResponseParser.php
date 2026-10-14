<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Infrastructure\Llm\JsonSchema;
use App\Infrastructure\Llm\JsonSchemaValidatorInterface;

/**
 * Gemini is documented to enforce only part of JSON Schema (not maxLength), so the response is validated again here.
 */
final readonly class EvaluationResponseParser implements EvaluationResponseParserInterface
{
    public const int MAX_REASON_LENGTH = 1000;

    public function __construct(
        private JsonSchemaValidatorInterface $validator,
    ) {
    }

    public function schema(): JsonSchema
    {
        return JsonSchema::fromString(json_encode(self::definition(), \JSON_THROW_ON_ERROR));
    }

    public function parse(string $rawResponse): JobPostingEvaluation
    {
        $errors = $this->validator->validate($rawResponse, $this->schema());
        if ([] !== $errors) {
            throw InvalidEvaluationResponse::schemaViolation($errors);
        }

        /**
         * The shape is guaranteed by the schema validation above.
         *
         * @var array{reason: string, verdict: string} $data
         */
        $data = json_decode($rawResponse, true, flags: \JSON_THROW_ON_ERROR);

        try {
            return new JobPostingEvaluation(
                JobPostingVerdict::from($data['verdict']),
                StatusReason::fromString($data['reason']),
            );
        } catch (EmptyStatusReason $exception) {
            throw InvalidEvaluationResponse::invalidValue($exception);
        }
    }

    /**
     * The reason comes before the verdict, so the model writes out its grounds before committing to one.
     *
     * @return array<string, mixed>
     */
    private static function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'maxLength' => self::MAX_REASON_LENGTH,
                    'description' => 'One or two sentences in English on the deciding factors',
                ],
                'verdict' => [
                    'type' => 'string',
                    'enum' => array_map(
                        static fn (JobPostingVerdict $verdict): string => $verdict->value,
                        JobPostingVerdict::cases(),
                    ),
                ],
            ],
            'required' => ['reason', 'verdict'],
            'additionalProperties' => false,
        ];
    }
}
