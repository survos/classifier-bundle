<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Vocabulary;

use Survos\ClassifierBundle\Model\Concept;
use Survos\ClassifierBundle\Model\RuleSet;

final class ArrayVocabulary implements VocabularyInterface
{
    /** @var array<string, Concept> */
    private array $concepts = [];

    /** @var array<string, list<Concept>> parent code ('' for roots) => assignable children */
    private array $children = [];

    /** @param iterable<Concept> $concepts */
    public function __construct(private readonly string $name, iterable $concepts)
    {
        foreach ($concepts as $concept) {
            if (isset($this->concepts[$concept->code])) {
                throw new \InvalidArgumentException(sprintf('Duplicate concept "%s" in vocabulary "%s".', $concept->code, $name));
            }
            $this->concepts[$concept->code] = $concept;
        }
        foreach ($this->concepts as $concept) {
            if ($concept->parentCode !== null && !isset($this->concepts[$concept->parentCode])) {
                throw new \InvalidArgumentException(sprintf('Concept "%s" has unknown parent "%s".', $concept->code, $concept->parentCode));
            }
            if (!$concept->retired) {
                $this->children[$concept->parentCode ?? ''][] = $concept;
            }
        }
    }

    /**
     * Rows as an app would export them: {code, label, definition?, parent?, triggerTerms?,
     * properNouns?, exceptions?, regularExpressions?, rules?, threshold?}; see RuleSet::fromRules() for `rules`.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public static function fromRows(string $name, iterable $rows): self
    {
        $concepts = [];
        foreach ($rows as $row) {
            $concepts[] = new Concept(
                (string) $row['code'],
                (string) ($row['label'] ?? $row['code']),
                (string) ($row['definition'] ?? ''),
                isset($row['parent']) ? (string) $row['parent'] : null,
                RuleSet::fromTagFields($row['triggerTerms'] ?? [], $row['properNouns'] ?? [], $row['exceptions'] ?? [], $row['regularExpressions'] ?? [])
                    ->with(RuleSet::fromRules($row['rules'] ?? [], (int) ($row['threshold'] ?? 1))),
            );
        }

        return new self($name, $concepts);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isTree(): bool
    {
        return array_keys($this->children) !== [''] && $this->children !== [];
    }

    public function get(string $code): ?Concept
    {
        return $this->concepts[$code] ?? null;
    }

    public function all(): array
    {
        return array_values(array_filter($this->concepts, static fn (Concept $c): bool => !$c->retired));
    }

    public function roots(): array
    {
        return $this->children[''] ?? [];
    }

    public function children(string $code): array
    {
        return $this->children[$code] ?? [];
    }
}
