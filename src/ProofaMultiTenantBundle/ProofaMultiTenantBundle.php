<?php

namespace App\ProofaMultiTenantBundle;

use App\ProofaMultiTenantBundle\DependencyInjection\Compiler\SecurityOverridePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class ProofaMultiTenantBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new SecurityOverridePass());
    }
}
