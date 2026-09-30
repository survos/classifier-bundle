<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Strategy;

use Survos\ClassifierBundle\Model\Assignment;
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Model\Result;
use Survos\ClassifierBundle\Model\RuleSet;
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
        $text = implode("\n", array_filter(array_map($document->text(...), $this->scopes), static fn (string $t): bool => $t !== ''));
        $assignments = [];
        $hasRules = false;
        foreach ($vocabulary->all() as $concept) {
            if ($concept->rules->isEmpty()) {
                continue;
            }
            $hasRules = true;
            [$score, $evidence] = $this->score($concept->rules, $text);
            if ($evidence !== [] && $score >= $concept->rules->threshold) {
                $assignments[] = new Assignment($vocabulary->name(), $concept->code, $this->name, (float) $score, $evidence);
            }
        }
        if (!$hasRules) {
            return Result::abstain($vocabulary->name(), $this->name, 'vocabulary has no rules');
        }

        return new Result($vocabulary->name(), $this->name, $assignments);
    }

    /** @return array{int, list<string>} */
    private function score(RuleSet $rules, string $text): array
    {
        foreach ($rules->exceptions as $exception) {
            $text = (string) preg_replace($exception->toRegex(), ' ', $text);
        }
        $score = 0;
        $evidence = [];
        foreach ($rules->terms as $term) {
            if (preg_match($term->toRegex(), $text) === 1) {
                $score += $term->score;
                $evidence[] = $term->pattern;
            }
        }

        return [$score, $evidence];
    }
}
