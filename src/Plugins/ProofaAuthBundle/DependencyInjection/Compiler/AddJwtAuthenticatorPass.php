<?php

namespace App\Plugins\ProofaAuthBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class AddJwtAuthenticatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $jwtAuthenticatorRef = new Reference(\App\Plugins\ProofaAuthBundle\Security\JwtAuthenticator::class);
        
        // 1. Add JwtAuthenticator to both API and secured_area firewalls
        $firewalls = ['security.authenticator.manager.api', 'security.authenticator.manager.secured_area'];
        
        foreach ($firewalls as $managerId) {
            if (!$container->hasDefinition($managerId)) {
                continue;
            }
            
            $definition = $container->getDefinition($managerId);
            
            try {
                $authenticators = $definition->getArgument(0);
            } catch (\Exception $e) {
                continue;
            }
            
            if ($authenticators instanceof \Symfony\Component\DependencyInjection\Argument\IteratorArgument) {
                $values = $authenticators->getValues();
                
                $alreadyExists = false;
                foreach ($values as $auth) {
                    if ((string) $auth === \App\Plugins\ProofaAuthBundle\Security\JwtAuthenticator::class) {
                        $alreadyExists = true;
                        break;
                    }
                }
                
                if (!$alreadyExists) {
                    array_unshift($values, $jwtAuthenticatorRef);
                    $authenticators->setValues($values);
                }
            } elseif (is_array($authenticators)) {
                array_unshift($authenticators, $jwtAuthenticatorRef);
                $definition->replaceArgument(0, $authenticators);
            }
        }

        // 2. Add proofa_jwt to the chain_provider
        $chainProviderId = 'security.user.provider.concrete.chain_provider';
        if ($container->hasDefinition($chainProviderId)) {
            $chainDef = $container->getDefinition($chainProviderId);
            
            try {
                $providers = $chainDef->getArgument(0);
            } catch (\Exception $e) {
                return;
            }
            
            if ($providers instanceof \Symfony\Component\DependencyInjection\Argument\IteratorArgument) {
                $values = $providers->getValues();
                
                $alreadyExists = false;
                foreach ($values as $provider) {
                    if ((string) $provider === 'security.user.provider.concrete.proofa_jwt') {
                        $alreadyExists = true;
                        break;
                    }
                }
                
                if (!$alreadyExists) {
                    array_unshift($values, new Reference('security.user.provider.concrete.proofa_jwt'));
                    $providers->setValues($values);
                }
            }
        }
    }
}
