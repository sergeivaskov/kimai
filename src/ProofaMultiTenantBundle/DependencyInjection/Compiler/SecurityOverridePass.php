<?php

namespace App\ProofaMultiTenantBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;

class SecurityOverridePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('security.access_map')) {
            return;
        }

        // Create a RequestMatcher for our webhook path
        $matcherDefinition = new Definition(PathRequestMatcher::class, ['^/api/webhooks']);
        $matcherId = 'proofa_multi_tenant.webhook_request_matcher';
        $container->setDefinition($matcherId, $matcherDefinition);

        $accessMapDefinition = $container->getDefinition('security.access_map');
        
        // We need to insert our rule at the beginning of the access map.
        // The access map has method calls to 'add'.
        $methodCalls = $accessMapDefinition->getMethodCalls();
        
        $newCall = ['add', [new Reference($matcherId), ['PUBLIC_ACCESS']]];
        
        // Prepend the new call so it matches before '^/api'
        array_unshift($methodCalls, $newCall);
        
        $accessMapDefinition->setMethodCalls($methodCalls);
    }
}
