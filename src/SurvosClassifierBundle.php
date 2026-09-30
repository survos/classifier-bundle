<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle;

use Survos\ClassifierBundle\Command\ClassifierCommands;
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Policy\AssignmentMerger;
use Survos\ClassifierBundle\Strategy\JevStrategy;
use Survos\ClassifierBundle\Strategy\RulesStrategy;
use Survos\ClassifierBundle\Strategy\StrategyInterface;
use Survos\ClassifierBundle\Vocabulary\ArrayVocabulary;
use Survos\ClassifierBundle\Vocabulary\CountriesByContinent;
use Survos\ClassifierBundle\Vocabulary\MediaTopicsVocabulary;
use Survos\ClassifierBundle\Vocabulary\UsMetroAreas;
use Survos\ClassifierBundle\Vocabulary\VocabularyInterface;
use Survos\ClassifierBundle\Vocabulary\VocabularyRegistry;
use Survos\Kit\AbstractSurvosBundle;
use Survos\MediaTopics\MediaTopics;
use Symfony\AI\Platform\Bridge\TypeSafe\Evaluation;
use Symfony\AI\Platform\Bridge\TypeSafe\Factory;
use Symfony\AI\Platform\Platform;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
class SurvosClassifierBundle extends AbstractSurvosBundle
{
    public const string VOCABULARY_TAG = 'survos_classifier.vocabulary';
    public const string STRATEGY_TAG = 'survos_classifier.strategy';

    protected function twigNamespace(): ?string
    {
        return null;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('jev')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultValue(class_exists(Evaluation::class))->info('Needs symfony/ai-type-safe-platform')->end()
                        ->scalarNode('platform')->defaultNull()->info('Platform service id, e.g. ai.platform.typesafe; default: one built from api_key')->end()
                        ->scalarNode('api_key')->defaultValue('%env(default::TYPESAFE_API_KEY)%')->end()
                        ->scalarNode('model')->defaultValue('jev-1.13.0')->info('Pinned, so tuned thresholds stay valid')->end()
                        ->floatNode('threshold')->defaultValue(0.5)->info('Minimum probability to assign a label')->end()
                        ->integerNode('beam')->defaultValue(3)->info('Paths kept while walking a tree')->end()
                        ->floatNode('min_path_score')->defaultValue(0.5)->end()
                        ->floatNode('min_separation')->defaultValue(1.2)->info('Best path must beat a rival by this factor')->end()
                        ->arrayNode('modes')->info('vocabulary => labels|walk')->scalarPrototype()->end()->end()
                        ->arrayNode('questions')->info('vocabulary => yes/no question with {label}')->scalarPrototype()->end()->end()
                        ->scalarNode('cache')->defaultValue('cache.app')->info('Cache pool for responses; null to disable')->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array<mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        // Apps add their own by implementing the interface (lawsuits from Doctrine, a TagVoteStrategy, ...).
        $builder->registerForAutoconfiguration(VocabularyInterface::class)->addTag(self::VOCABULARY_TAG);
        $builder->registerForAutoconfiguration(StrategyInterface::class)->addTag(self::STRATEGY_TAG);

        $services = $container->services();
        if (class_exists(MediaTopics::class)) {
            // Shares the instance survos/media-topics-bundle registers, when that bundle is installed.
            $services->set('survos_classifier.vocabulary.media_topics', ArrayVocabulary::class)
                ->factory([MediaTopicsVocabulary::class, 'create'])
                ->args([new Reference(MediaTopics::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)])
                ->tag(self::VOCABULARY_TAG);
        }
        $services->set('survos_classifier.vocabulary.country', ArrayVocabulary::class)
            ->factory([CountriesByContinent::class, 'load'])
            ->tag(self::VOCABULARY_TAG);

        $services->set('survos_classifier.vocabulary.us_metro', ArrayVocabulary::class)
            ->factory([UsMetroAreas::class, 'load'])
            ->tag(self::VOCABULARY_TAG);

        $services->set(VocabularyRegistry::class)->args([tagged_iterator(self::VOCABULARY_TAG)]);
        $services->set(RulesStrategy::class)->tag(self::STRATEGY_TAG);
        // The baseline: news' original tagger only ever read the headline.
        $services->set('survos_classifier.strategy.rules_headline', RulesStrategy::class)
            ->args([[Document::SCOPE_HEADLINE], RulesStrategy::NAME_HEADLINE])
            ->tag(self::STRATEGY_TAG);
        $services->set(AssignmentMerger::class);

        $jev = $config['jev'];
        if ($jev['enabled']) {
            if (!class_exists(Evaluation::class)) {
                throw new \LogicException('survos_classifier.jev needs symfony/ai-type-safe-platform.');
            }
            $platform = $jev['platform'] ?? 'survos_classifier.jev.platform';
            if ($jev['platform'] === null) {
                $services->set($platform, Platform::class)->factory([Factory::class, 'createPlatform'])->args([$jev['api_key']]);
            }
            $services->set(JevStrategy::class)
                ->args([
                    new Reference($platform), $jev['model'], $jev['threshold'], $jev['beam'], $jev['min_path_score'], $jev['min_separation'],
                    // Countries are a tree, but an article can concern several: ask about each one.
                    $jev['modes'] + [CountriesByContinent::NAME => JevStrategy::MODE_LABELS],
                    $jev['questions'],
                ])
                ->arg('$cache', $jev['cache'] === null ? null : new Reference($jev['cache']))
                ->tag(self::STRATEGY_TAG);
        }

        $services->get(ClassifierCommands::class)->arg('$strategies', tagged_iterator(self::STRATEGY_TAG));
    }
}
