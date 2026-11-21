<?php

declare(strict_types=1);

namespace App\Domain\Llm;

enum LlmFailureCause: string
{
    case ModelNotSelected = 'model_not_selected';
    case ApiKeyNotSet = 'api_key_not_set';
    case ApiKeyUnreadable = 'api_key_unreadable';
    case FileUnreadable = 'file_unreadable';
    case InvalidResponse = 'invalid_response';
    case EmptyResponse = 'empty_response';
    case CvNotAnalysed = 'cv_not_analysed';
    case Provider = 'provider';
    case Unexpected = 'unexpected';
}
