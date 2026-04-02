<?php

namespace App\Plugins\ProofaAuthBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

class ProofaAuthExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $securityConfig = [
            'providers' => [
                'proofa_jwt' => [
                    'id' => \App\Plugins\ProofaAuthBundle\Security\JwtUserProvider::class,
                ],
            ],
        ];

        $container->prependExtensionConfig('security', $securityConfig);
    }
}
