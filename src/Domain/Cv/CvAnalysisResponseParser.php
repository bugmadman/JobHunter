<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Entity\Skill;
use App\Infrastructure\Llm\JsonSchema;
use App\Infrastructure\Llm\JsonSchemaValidatorInterface;

/**
 * Gemini is documented to enforce only part of JSON Schema, so the response is validated again here.
 */
final readonly class CvAnalysisResponseParser implements CvAnalysisResponseParserInterface
{
    /**
     * The code formats are checked by the domain VOs; this only keeps a runaway string out of their error messages.
     */
    private const int MAX_CODE_LENGTH = 16;

    public function __construct(
        private JsonSchemaValidatorInterface $validator,
    ) {
    }

    public function schema(): JsonSchema
    {
        return JsonSchema::fromString(json_encode(self::definition(), \JSON_THROW_ON_ERROR));
    }

    public function parse(string $rawResponse): ParsedCvAnalysis
    {
        $errors = $this->validator->validate($rawResponse, $this->schema());
        if ([] !== $errors) {
            throw InvalidCvAnalysisResponse::schemaViolation($errors);
        }

        /**
         * The shape is guaranteed by the schema validation above.
         *
         * @var array{
         *     original_language_text: string,
         *     english_text: string,
         *     skills: list<non-empty-string>,
         *     years_experience: int|null,
         *     seniority: string|null,
         *     languages: list<array{language: string, level: string}>,
         *     salary_expectation: array{min: int|null, max: int|null, currency: string}|null,
         * } $data
         */
        $data = json_decode($rawResponse, true, flags: \JSON_THROW_ON_ERROR);

        try {
            $salary = $data['salary_expectation'];

            return new ParsedCvAnalysis(
                new CvAnalysis(
                    $data['original_language_text'],
                    $data['english_text'],
                    $data['years_experience'],
                    null === $data['seniority'] ? null : Seniority::from($data['seniority']),
                    null === $salary ? null : SalaryExpectation::create(
                        $salary['min'],
                        $salary['max'],
                        $salary['currency'],
                    ),
                    array_map(
                        static fn (array $language): LanguageProficiency => LanguageProficiency::create(
                            $language['language'],
                            LanguageLevel::from($language['level']),
                        ),
                        $data['languages'],
                    ),
                ),
                $data['skills'],
            );
        } catch (InvalidCvAnalysis|InvalidLanguageProficiency|InvalidSalaryExpectation $exception) {
            throw InvalidCvAnalysisResponse::invalidValue($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(): array
    {
        $nullable = static fn (array $schema): array => ['anyOf' => [$schema, ['type' => 'null']]];
        $object = static fn (array $properties): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
        $amount = $nullable(['type' => 'integer', 'minimum' => 1, 'maximum' => SalaryExpectation::MAX_AMOUNT]);

        return $object([
            'original_language_text' => [
                'type' => 'string',
                'description' => 'The CV analysis in the language the CV is written in',
            ],
            'english_text' => [
                'type' => 'string',
                'description' => 'The same analysis in English',
            ],
            'skills' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'pattern' => '\S', 'maxLength' => Skill::MAX_NAME_LENGTH],
            ],
            'years_experience' => $nullable([
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => CvAnalysis::MAX_YEARS_EXPERIENCE,
                'description' => 'Whole years of professional experience',
            ]),
            'seniority' => $nullable([
                'type' => 'string',
                'enum' => self::values(Seniority::cases()),
            ]),
            'languages' => [
                'type' => 'array',
                'description' => 'Spoken human languages with a CEFR level, not programming languages',
                'items' => $object([
                    'language' => [
                        'type' => 'string',
                        'maxLength' => self::MAX_CODE_LENGTH,
                        'description' => 'ISO 639-1 two-letter code, e.g. "en"',
                    ],
                    'level' => [
                        'type' => 'string',
                        'enum' => self::values(LanguageLevel::cases()),
                    ],
                ]),
            ],
            'salary_expectation' => $nullable($object([
                'min' => $amount,
                'max' => $amount,
                'currency' => [
                    'type' => 'string',
                    'maxLength' => self::MAX_CODE_LENGTH,
                    'description' => 'ISO 4217 code, e.g. "USD"',
                ],
            ]) + ['description' => 'Expected monthly salary; an hourly or yearly figure is converted to monthly']),
        ]);
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return list<int|string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $cases);
    }
}
