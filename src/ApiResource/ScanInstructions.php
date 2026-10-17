<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\ScanInstructionsProvider;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\GenericType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;

#[ApiResource(
    shortName: 'ScanInstructions',
    operations: [
        new Get(
            uriTemplate: '/scan-instructions',
            formats: ['json' => ['application/json']],
            openapi: new Operation(
                summary: 'Tells a collecting agent which platforms to scan, how, and where to send the postings.',
                description: 'Follow the instruction of every listed platform and send each collected job posting '
                    .'to the intake (intake.method to intake.url), one posting per request, with the body '
                    .'described in the OpenAPI document at intake.docsUrl. Platforms without an instruction '
                    .'are not listed: do not scan them.',
            ),
            provider: ScanInstructionsProvider::class,
            errors: [],
        ),
    ],
)]
final readonly class ScanInstructions
{
    /**
     * @param list<PlatformScanInstruction> $platforms
     */
    public function __construct(
        #[ApiProperty(description: 'Where to send the collected job postings.')]
        public IntakeEndpoint $intake,
        // Without a PHPDoc parser installed the docs would not see the item type of the list
        #[ApiProperty(
            description: 'The platforms to scan, ordered by name. A platform without an instruction is not listed.',
            nativeType: new CollectionType(
                new GenericType(
                    new BuiltinType(TypeIdentifier::ARRAY),
                    new BuiltinType(TypeIdentifier::INT),
                    new ObjectType(PlatformScanInstruction::class),
                ),
                isList: true,
            ),
        )]
        public array $platforms,
    ) {
    }
}
