<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cv;

use App\Domain\Cv\CvAnalysisResponseParser;
use App\Domain\Cv\InvalidCvAnalysis;
use App\Domain\Cv\InvalidCvAnalysisResponse;
use App\Domain\Cv\InvalidLanguageProficiency;
use App\Domain\Cv\InvalidSalaryExpectation;
use App\Domain\Cv\LanguageLevel;
use App\Domain\Cv\SalaryExpectation;
use App\Domain\Cv\Seniority;
use App\Infrastructure\Llm\JsonSchemaValidator;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Uses the real validator: the schema itself is what is under test.
 */
final class CvAnalysisResponseParserTest extends TestCase
{
    private CvAnalysisResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CvAnalysisResponseParser(new JsonSchemaValidator());
    }

    public function testSchemaIsAValidJsonSchema(): void
    {
        $schema = $this->parser->schema()->value;
        $decoded = json_decode($schema, flags: \JSON_THROW_ON_ERROR);
        $empty = new \stdClass();

        $validator = new Validator();
        $validator->validate($empty, $decoded, Constraint::CHECK_MODE_VALIDATE_SCHEMA);

        self::assertSame([], $validator->getErrors(Validator::ERROR_SCHEMA_VALIDATION));
    }

    public function testFullResponseIsParsed(): void
    {
        $parsed = $this->parser->parse($this->encode(self::validResponse()));
        $analysis = $parsed->analysis;

        self::assertSame('Анализ резюме', $analysis->originalLanguageText);
        self::assertSame('CV analysis', $analysis->englishText);
        self::assertSame(12, $analysis->yearsExperience);
        self::assertSame(Seniority::Senior, $analysis->seniority);
        $salary = $analysis->salaryExpectation;
        self::assertNotNull($salary);
        self::assertSame(4000, $salary->min);
        self::assertSame(6000, $salary->max);
        self::assertSame('USD', $salary->currency);
        self::assertCount(2, $analysis->languages);
        self::assertSame('ru', $analysis->languages[0]->language);
        self::assertSame(LanguageLevel::Native, $analysis->languages[0]->level);
        self::assertSame('en', $analysis->languages[1]->language);
        self::assertSame(LanguageLevel::B2, $analysis->languages[1]->level);
        self::assertSame(['PHP', 'Symfony', 'PostgreSQL'], $parsed->skillNames);
    }

    public function testUnknownValuesMayBeNullOrEmpty(): void
    {
        $parsed = $this->parser->parse($this->encode([
            ...self::validResponse(),
            'skills' => [],
            'years_experience' => null,
            'seniority' => null,
            'languages' => [],
            'salary_expectation' => null,
        ]));

        self::assertNull($parsed->analysis->yearsExperience);
        self::assertNull($parsed->analysis->seniority);
        self::assertNull($parsed->analysis->salaryExpectation);
        self::assertSame([], $parsed->analysis->languages);
        self::assertSame([], $parsed->skillNames);
    }

    public function testOneSalaryBoundIsEnough(): void
    {
        $parsed = $this->parser->parse($this->encode([
            ...self::validResponse(),
            'salary_expectation' => ['min' => null, 'max' => 5000, 'currency' => 'EUR'],
        ]));

        self::assertNull($parsed->analysis->salaryExpectation?->min);
        self::assertSame(5000, $parsed->analysis->salaryExpectation?->max);
    }

    #[TestWith([0])]
    #[TestWith([70])]
    public function testYearsExperienceBoundsAreAccepted(int $years): void
    {
        $parsed = $this->parser->parse($this->encode([...self::validResponse(), 'years_experience' => $years]));

        self::assertSame($years, $parsed->analysis->yearsExperience);
    }

    public function testSkillOfMaxLengthInCharactersIsAccepted(): void
    {
        $skill = str_repeat('é', 100);

        $parsed = $this->parser->parse($this->encode([...self::validResponse(), 'skills' => [$skill]]));

        self::assertSame([$skill], $parsed->skillNames);
    }

    #[TestWith([''])]
    #[TestWith(['not json'])]
    #[TestWith(['{"original_language_text": "Анализ"'])]
    public function testResponseThatIsNotJsonIsRejected(string $raw): void
    {
        $exception = $this->parseInvalid($raw);

        self::assertStringStartsWith('Not valid JSON', $exception->errors[0]);
    }

    #[TestWith(['original_language_text'])]
    #[TestWith(['english_text'])]
    #[TestWith(['skills'])]
    #[TestWith(['years_experience'])]
    #[TestWith(['seniority'])]
    #[TestWith(['languages'])]
    #[TestWith(['salary_expectation'])]
    public function testMissingFieldIsRejected(string $field): void
    {
        $response = self::validResponse();
        unset($response[$field]);

        $exception = $this->parseInvalid($this->encode($response));

        self::assertStringContainsString($field, $exception->getMessage());
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('schemaViolations')]
    public function testSchemaViolationIsRejected(array $override): void
    {
        $exception = $this->parseInvalid($this->encode([...self::validResponse(), ...$override]));

        self::assertNull($exception->getPrevious());
        self::assertNotEmpty($exception->errors);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function schemaViolations(): iterable
    {
        yield 'skills as a string' => [['skills' => 'PHP, Symfony']];
        yield 'unknown seniority' => [['seniority' => 'principal']];
        yield 'unknown language level' => [['languages' => [['language' => 'en', 'level' => 'fluent']]]];
        yield 'negative years' => [['years_experience' => -1]];
        yield 'a year instead of a count' => [['years_experience' => 2015]];
        yield 'fractional years' => [['years_experience' => 3.5]];
        yield 'years as a string' => [['years_experience' => '12']];
        yield 'blank skill' => [['skills' => ['PHP', ' ']]];
        yield 'empty skill' => [['skills' => ['']]];
        yield 'skill too long' => [['skills' => [str_repeat('a', 101)]]];
        yield 'zero salary' => [['salary_expectation' => ['min' => 0, 'max' => 5000, 'currency' => 'USD']]];
        yield 'negative salary maximum' => [
            ['salary_expectation' => ['min' => null, 'max' => -1, 'currency' => 'USD']],
        ];
        yield 'salary above the cap' => [
            ['salary_expectation' => ['min' => SalaryExpectation::MAX_AMOUNT + 1, 'max' => null, 'currency' => 'USD']],
        ];
        yield 'runaway language code' => [['languages' => [['language' => str_repeat('e', 17), 'level' => 'b2']]]];
        yield 'runaway currency' => [
            ['salary_expectation' => ['min' => 1, 'max' => null, 'currency' => str_repeat('U', 17)]],
        ];
        yield 'unknown salary field' => [
            ['salary_expectation' => ['min' => 1, 'max' => null, 'currency' => 'USD', 'period' => 'month']],
        ];
        yield 'salary without currency' => [['salary_expectation' => ['min' => 4000, 'max' => 5000]]];
        yield 'unknown field' => [['summary' => 'Extra']];
        yield 'unknown language field' => [['languages' => [['language' => 'en', 'level' => 'b2', 'note' => 'IELTS']]]];
    }

    public function testResponseThatIsNotAnObjectIsRejected(): void
    {
        self::assertStringStartsWith('(root): ', $this->parseInvalid('[]')->errors[0]);
    }

    /**
     * @param array<string, mixed>     $override
     * @param class-string<\Throwable> $domainException
     */
    #[DataProvider('invalidValues')]
    public function testInvalidDomainValueIsRejected(array $override, string $domainException): void
    {
        $exception = $this->parseInvalid($this->encode([...self::validResponse(), ...$override]));

        self::assertInstanceOf($domainException, $exception->getPrevious());
        self::assertSame([$exception->getPrevious()->getMessage()], $exception->errors);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, class-string<\Throwable>}>
     */
    public static function invalidValues(): iterable
    {
        yield 'blank original text' => [['original_language_text' => ' '], InvalidCvAnalysis::class];
        yield 'blank english text' => [['english_text' => ''], InvalidCvAnalysis::class];
        yield 'duplicate language' => [
            ['languages' => [['language' => 'en', 'level' => 'b2'], ['language' => 'EN', 'level' => 'c1']]],
            InvalidCvAnalysis::class,
        ];
        yield 'language name instead of a code' => [
            ['languages' => [['language' => 'English', 'level' => 'b2']]],
            InvalidLanguageProficiency::class,
        ];
        yield 'salary without amounts' => [
            ['salary_expectation' => ['min' => null, 'max' => null, 'currency' => 'USD']],
            InvalidSalaryExpectation::class,
        ];
        yield 'salary min above max' => [
            ['salary_expectation' => ['min' => 6000, 'max' => 4000, 'currency' => 'USD']],
            InvalidSalaryExpectation::class,
        ];
        yield 'currency symbol instead of a code' => [
            ['salary_expectation' => ['min' => 4000, 'max' => null, 'currency' => '$']],
            InvalidSalaryExpectation::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validResponse(): array
    {
        return [
            'original_language_text' => 'Анализ резюме',
            'english_text' => 'CV analysis',
            'skills' => ['PHP', 'Symfony', 'PostgreSQL'],
            'years_experience' => 12,
            'seniority' => 'senior',
            'languages' => [
                ['language' => 'ru', 'level' => 'native'],
                ['language' => 'en', 'level' => 'b2'],
            ],
            'salary_expectation' => ['min' => 4000, 'max' => 6000, 'currency' => 'USD'],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function encode(array $response): string
    {
        return json_encode($response, \JSON_THROW_ON_ERROR);
    }

    private function parseInvalid(string $raw): InvalidCvAnalysisResponse
    {
        try {
            $this->parser->parse($raw);
        } catch (InvalidCvAnalysisResponse $exception) {
            return $exception;
        }

        self::fail('The response was accepted.');
    }
}
