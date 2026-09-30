<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Vocabulary;

/** Every vocabulary the bundle or the app registered (services tagged survos_classifier.vocabulary). */
final class VocabularyRegistry
{
    /** @var array<string, VocabularyInterface> */
    private array $vocabularies = [];

    /** @param iterable<VocabularyInterface> $vocabularies */
    public function __construct(iterable $vocabularies = [])
    {
        foreach ($vocabularies as $vocabulary) {
            $this->vocabularies[$vocabulary->name()] = $vocabulary;
        }
    }

    public function get(string $name): VocabularyInterface
    {
        return $this->vocabularies[$name] ?? throw new \InvalidArgumentException(sprintf(
            'Unknown vocabulary "%s". Known: %s.', $name, implode(', ', array_keys($this->vocabularies)) ?: '(none)',
        ));
    }

    /** @return array<string, VocabularyInterface> */
    public function all(): array
    {
        return $this->vocabularies;
    }
}
