<?php

namespace App\Log\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class CorrelationIdProcessor implements ProcessorInterface
{
    private RequestStack $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->attributes->has('_correlation_id')) {
            $record->extra['correlation_id'] = $request->attributes->get('_correlation_id');
        }

        return $record;
    }
}
