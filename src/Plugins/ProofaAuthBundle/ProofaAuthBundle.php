<?php

namespace App\Plugins\ProofaAuthBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use App\Plugins\ProofaAuthBundle\DependencyInjection\Compiler\AddJwtAuthenticatorPass;

class ProofaAuthBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new AddJwtAuthenticatorPass());
    }
}
