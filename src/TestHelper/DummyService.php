<?php

namespace App\TestHelper;

use Psr\Log\LoggerInterface;

class DummyService
{
    private LoggerInterface $logger;
    
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    public function processData(string $data): string
    {
        $this->logger->info('Processing data', ['data' => $data]);
        return strtoupper($data);
    }
}
