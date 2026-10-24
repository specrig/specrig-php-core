<?php

declare(strict_types=1);

namespace Specrig\Behat;

use Behat\Behat\Context\ServiceContainer\ContextExtension;
use Behat\Behat\Tester\ServiceContainer\TesterExtension;
use Behat\Testwork\EventDispatcher\ServiceContainer\EventDispatcherExtension;
use Behat\Testwork\ServiceContainer\Extension as BehatExtension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Behat\Testwork\Suite\ServiceContainer\SuiteExtension;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class Extension implements BehatExtension
{
    public function getConfigKey(): string
    {
        return 'specrig';
    }

    public function initialize(ExtensionManager $extensionManager): void
    {
    }

    public function configure(ArrayNodeDefinition $builder): void
    {
        $builder
            ->children()
                ->scalarNode('harness')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('features')->defaultValue('%paths.base%/features')->end()
                ->scalarNode('steps')->defaultValue('%paths.base%/steps')->end()
            ->end();
    }

    /** @param array{harness: string, features: string, steps: string} $config */
    public function load(ContainerBuilder $container, array $config): void
    {
        $container->setDefinition('specrig.cases', new Definition(Cases::class, [
            $config['harness'],
            '%paths.base%',
            $config['features'],
        ]));

        $resolver = new Definition(Resolver::class, [new Reference('specrig.cases')]);
        $resolver->addTag(ContextExtension::ARGUMENT_RESOLVER_TAG);
        $container->setDefinition('specrig.resolver', $resolver);

        $listener = new Definition(Listener::class, [new Reference('specrig.cases')]);
        $listener->addTag(EventDispatcherExtension::SUBSCRIBER_TAG);
        $container->setDefinition('specrig.listener', $listener);

        $suites = new Definition(Suites::class, ['%suite.generic.default_settings%', $config['steps']]);
        $suites->addTag(SuiteExtension::GENERATOR_TAG, ['priority' => 100]);
        $container->setDefinition('specrig.suites', $suites);

        foreach ([TesterExtension::SCENARIO_TESTER_ID => TesterExtension::SCENARIO_TESTER_WRAPPER_TAG, TesterExtension::EXAMPLE_TESTER_ID => TesterExtension::EXAMPLE_TESTER_WRAPPER_TAG] as $tester => $tag) {
            $wrapper = new Definition(Tester::class, [new Reference($tester), new Reference('specrig.cases')]);
            $wrapper->addTag($tag, ['priority' => 0]);
            $container->setDefinition("{$tag}.specrig", $wrapper);
        }
    }

    public function process(ContainerBuilder $container): void
    {
    }
}
