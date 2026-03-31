<?php

namespace App\TestHelper;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class ComplexService
{
    private EntityManagerInterface $em;
    private RequestStack $requestStack;
    private LoggerInterface $logger;
    
    public function __construct(
        EntityManagerInterface $em,
        RequestStack $requestStack,
        LoggerInterface $logger
    ) {
        $this->em = $em;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }
    
    public function process(): array
    {
        return ['status' => 'ok'];
    }
}
