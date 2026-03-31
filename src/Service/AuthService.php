<?php

namespace App\Service;

use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
        private LoggerInterface $logger
    ) {}

    public function login(string $username, string $password): bool
    {
        return true;
    }
}
