<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Strategy;

use Survos\ClassifierBundle\Model\Assignment;
use Survos\ClassifierBundle\Model\Concept;
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Model\Result;
use Survos\ClassifierBundle\Model\RuleSet;
use Survos\ClassifierBundle\Model\Term;
use Survos\ClassifierBundle\Vocabulary\VocabularyInterface;

/**
 * Trigger terms, proper nouns and exceptions: a concept is assigned when the terms found in the
 * text add up to its threshold. Every assignable concept is tested, so several labels can apply.
 */
final class RulesStrategy implements StrategyInterface
{
    public const string NAME = 'rules';
    public const string NAME_HEADLINE = 'rules_headline';

    /** @param list<string> $scopes Document scopes to read; news' original tagger read the headline only */
    public function __construct(
        private readonly array $scopes = [Document::SCOPE_HEADLINE, Document::SCOPE_SUMMARY],
        private readonly string $name = self::NAME,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function classify(Document $document, VocabularyInterface $vocabulary): Result
    {
        $assignments = [];
        $hasRules = false;
        foreach ($vocabulary->all() as $concept) {
            if ($concept->rules->isEmpty()) {
                continue;
            }
            $hasRules = true;
            $assignment = $this->evaluate($concept, $document, $vocabulary->name());
            if ($assignment !== null) {
                $assignments[] = $assignment;
            }
        }
        if (!$hasRules) {
            return Result::abstain($vocabulary->name(), $this->name, 'vocabulary has no rules');
        }

        return new Result($vocabulary->name(), $this->name, $assignments);
    }

    /**
     * Decisive rules first: a metadata or text rule that accepts settles the concept and nothing
     * else is consulted; one that rejects withholds it. Otherwise scores are added (negative ones
     * take points away) and the concept is assigned when the total reaches the threshold.
     */
    private function evaluate(Concept $concept, Document $document, string $vocabulary): ?Assignment
    {
        $rules = $concept->rules;
        $texts = [];
        $text = function (?string $scope) use (&$texts, $rules, $document): string {
            return $texts[$scope ?? ''] ??= $this->masked($rules, $scope === null
            ? implode("\n", array_filter(array_map($document->text(...), $this->scopes), static fn (string $t): bool => $t !== ''))
            : $document->text($scope));
        };
        $matches = fn (Term $term): bool => $term->meta !== null
            ? $term->matchesMetadata($document)
            : preg_match($term->toRegex(), $text($term->scope)) === 1;

        foreach ($rules->terms as $term) {
            if ($term->decision !== null && $matches($term)) {
                return $term->decision === Term::ACCEPT
                    ? new Assignment($vocabulary, $concept->code, $this->name, (float) max($term->score, RuleSet::STRONG), [$this->describe($term)], decisive: true)
                    : null;
            }
        }
        $score = 0;
        $evidence = [];
        foreach ($rules->terms as $term) {
            if ($term->decision === null && $matches($term)) {
                $score += $term->score;
                $evidence[] = $this->describe($term);
            }
        }

        return $evidence !== [] && $score >= $rules->threshold
            ? new Assignment($vocabulary, $concept->code, $this->name, (float) $score, $evidence)
            : null;
    }

    private function masked(RuleSet $rules, string $text): string
    {
        foreach ($rules->exceptions as $exception) {
            $text = (string) preg_replace($exception->toRegex(), ' ', $text);
        }

        return $text;
    }

    private function describe(Term $term): string
    {
        return ($term->meta !== null ? $term->meta.'='.$term->pattern : $term->pattern)
            .($term->score < 0 ? sprintf(' (%d)', $term->score) : '');
    }
}
