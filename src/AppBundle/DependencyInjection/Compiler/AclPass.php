<?php

namespace AppBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

class AclPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $basicAclDefinition = $container
            ->getDefinition('security.acl.voter.basic_permissions')
            ->clearTag('security.voter')
        ;

        $container->setDefinition('app.acl.voter', (clone $basicAclDefinition)->setPublic(true));
    }
}

