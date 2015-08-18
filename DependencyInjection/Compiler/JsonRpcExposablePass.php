<?php
namespace Wa72\JsonRpcBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 *
 */
class JsonRpcExposablePass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('wa72_jsonrpc.jsonrpccontroller');
        $services_wa72 = $container->findTaggedServiceIds('wa72_jsonrpc.exposable');
        $services_itscaro = $container->findTaggedServiceIds('itscaro_jsonrpc.exposable');
        $services = array_merge($services_wa72, $services_itscaro);
        foreach ($services as $service => $attributes) {
            $definition->addMethodCall('addService', array($service));
        }
    }
}
