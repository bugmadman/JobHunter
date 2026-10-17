<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\IntakeEndpoint;
use App\ApiResource\PlatformScanInstruction;
use App\ApiResource\ReceivedJobPosting;
use App\ApiResource\ScanInstructions;
use App\Repository\ScanInstructionRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @implements ProviderInterface<ScanInstructions>
 */
final readonly class ScanInstructionsProvider implements ProviderInterface
{
    public function __construct(
        private ScanInstructionRepositoryInterface $instructions,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ScanInstructions
    {
        return new ScanInstructions(
            new IntakeEndpoint(
                Request::METHOD_POST,
                $this->urls->generate(ReceivedJobPosting::INTAKE_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->urls->generate('api_doc', ['_format' => 'jsonopenapi'], UrlGeneratorInterface::ABSOLUTE_URL),
            ),
            array_map(
                PlatformScanInstruction::fromScanInstruction(...),
                $this->instructions->findAllOrderedByPlatformName(),
            ),
        );
    }
}
