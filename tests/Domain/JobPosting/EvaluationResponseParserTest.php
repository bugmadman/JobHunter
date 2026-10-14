<?php

declare(strict_types=1);

namespace App\Tests\Domain\JobPosting;

use App\Domain\JobPosting\EmptyStatusReason;
use App\Domain\JobPosting\EvaluationResponseParser;
use App\Domain\JobPosting\InvalidEvaluationResponse;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Infrastructure\Llm\JsonSchemaValidator;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Uses the real validator: the schema itself is what is under test.
 */
final class EvaluationResponseParserTest extends TestCase
{
    private EvaluationResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EvaluationResponseParser(new JsonSchemaValidator());
    }

    public function testSchemaIsAValidJsonSchema(): void
    {
        $decoded = json_decode($this->parser->schema()->value, flags: \JSON_THROW_ON_ERROR);

        $empty = new \stdClass();

        $validator = new Validator();
        $validator->validate($empty, $decoded, Constraint::CHECK_MODE_VALIDATE_SCHEMA);

        self::assertSame([], $validator->getErrors(Validator::ERROR_SCHEMA_VALIDATION));
    }

    public function testSchemaAsksForTheReasonBeforeTheVerdict(): void
    {
        /** @var array{properties: array<string, mixed>} $schema */
        $schema = json_decode($this->parser->schema()->value, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['reason', 'verdict'], array_keys($schema['properties']));
    }

    #[TestWith(['like', JobPostingVerdict::Like])]
    #[TestWith(['dislike', JobPostingVerdict::Dislike])]
    public function testVerdictAndReasonAreParsed(string $verdict, JobPostingVerdict $expected): void
    {
        $evaluation = $this->parser->parse($this->encode([
            'reason' => " Senior Symfony role.\n",
            'verdict' => $verdict,
        ]));

        self::assertSame($expected, $evaluation->verdict);
        self::assertSame('Senior Symfony role.', $evaluation->reason->value);
    }

    public function testReasonOfMaxLengthInCharactersIsAccepted(): void
    {
        $reason = str_repeat('é', EvaluationResponseParser::MAX_REASON_LENGTH);

        $evaluation = $this->parser->parse($this->encode(['reason' => $reason, 'verdict' => 'like']));

        self::assertSame($reason, $evaluation->reason->value);
    }

    #[TestWith([''])]
    #[TestWith(['not json'])]
    #[TestWith(['{"reason": "Fits"'])]
    public function testResponseThatIsNotJsonIsRejected(string $raw): void
    {
        self::assertStringStartsWith('Not valid JSON', $this->parseInvalid($raw)->errors[0]);
    }

    #[TestWith(['reason'])]
    #[TestWith(['verdict'])]
    public function testMissingFieldIsRejected(string $field): void
    {
        $response = ['reason' => 'Fits.', 'verdict' => 'like'];
        unset($response[$field]);

        self::assertStringContainsString($field, $this->parseInvalid($this->encode($response))->getMessage());
    }

    /**
     * @param array<mixed> $response
     */
    #[DataProvider('schemaViolations')]
    public function testSchemaViolationIsRejected(array $response): void
    {
        $exception = $this->parseInvalid($this->encode($response));

        self::assertNull($exception->getPrevious());
        self::assertNotEmpty($exception->errors);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function schemaViolations(): iterable
    {
        yield 'unknown verdict' => [['reason' => 'Fits.', 'verdict' => 'maybe']];
        yield 'capitalised verdict' => [['reason' => 'Fits.', 'verdict' => 'Like']];
        yield 'verdict as a boolean' => [['reason' => 'Fits.', 'verdict' => true]];
        yield 'reason as a list' => [['reason' => ['Fits.'], 'verdict' => 'like']];
        yield 'null reason' => [['reason' => null, 'verdict' => 'like']];
        yield 'reason too long' => [
            ['reason' => str_repeat('a', EvaluationResponseParser::MAX_REASON_LENGTH + 1), 'verdict' => 'like'],
        ];
        yield 'unknown field' => [['reason' => 'Fits.', 'verdict' => 'like', 'score' => 8]];
        yield 'a list instead of an object' => [[['reason' => 'Fits.', 'verdict' => 'like']]];
    }

    #[TestWith([''])]
    #[TestWith([" \n\t"])]
    #[TestWith(["\u{00A0}\u{200B}"])]
    public function testBlankReasonIsRejected(string $reason): void
    {
        $exception = $this->parseInvalid($this->encode(['reason' => $reason, 'verdict' => 'dislike']));

        self::assertInstanceOf(EmptyStatusReason::class, $exception->getPrevious());
        self::assertSame([$exception->getPrevious()->getMessage()], $exception->errors);
    }

    /**
     * @param array<mixed> $response
     */
    private function encode(array $response): string
    {
        return json_encode($response, \JSON_THROW_ON_ERROR);
    }

    private function parseInvalid(string $raw): InvalidEvaluationResponse
    {
        try {
            $this->parser->parse($raw);
        } catch (InvalidEvaluationResponse $exception) {
            return $exception;
        }

        self::fail('The response was accepted.');
    }
}
