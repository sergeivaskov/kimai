<?php
namespace App\ProofaCoreBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Definition;
use App\ProofaCoreBundle\Log\Processor\CorrelationIdProcessor;
use App\ProofaCoreBundle\EventSubscriber\CorrelationIdSubscriber;

class ProofaCoreExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $processorDef = new Definition(CorrelationIdProcessor::class);
        $processorDef->setAutowired(true);
        $processorDef->setAutoconfigured(true);
        $processorDef->addTag('monolog.processor');
        $container->setDefinition(CorrelationIdProcessor::class, $processorDef);

        $subscriberDef = new Definition(CorrelationIdSubscriber::class);
        $subscriberDef->setAutowired(true);
        $subscriberDef->setAutoconfigured(true);
        $subscriberDef->addTag('kernel.event_subscriber');
        $container->setDefinition(CorrelationIdSubscriber::class, $subscriberDef);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $monologConfig = [
            'handlers' => [
                'json_file' => [
                    'type' => 'stream',
                    'path' => '/var/log/proofa/php-backend.jsonl',
                    'level' => 'debug',
                    'formatter' => 'monolog.formatter.json'
                ]
            ]
        ];
        
        $container->prependExtensionConfig('monolog', $monologConfig);
    }
}
