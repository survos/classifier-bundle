<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Model;

/**
 * How the rules strategy recognises one concept.
 *
 * Exceptions are phrases that contain a trigger without meaning it ("Alcohol, Tobacco and Firearms"
 * is not about tobacco). They are blanked out of the text before terms are matched, so an article
 * that also mentions the trigger on its own still qualifies.
 */
final readonly class RuleSet
{
    /**
     * @param list<Term> $terms
     * @param list<Term> $exceptions
     */
    public function __construct(
        public array $terms = [],
        public array $exceptions = [],
        public int $threshold = 1,
    ) {}

    /**
     * Reads news' Tag columns. Trigger-term syntax: "term!" and "term!!" are strong triggers,
     * "!term" is an exception; a term containing an uppercase letter is case-sensitive.
     *
     * @param list<string> $triggerTerms
     * @param list<string> $properNouns
     * @param list<string> $exceptions
     * @param list<string> $regularExpressions
     */
    public static function fromTagFields(array $triggerTerms = [], array $properNouns = [], array $exceptions = [], array $regularExpressions = []): self
    {
        $terms = $masks = [];
        foreach ($triggerTerms as $raw) {
            $raw = trim((string) $raw);
            $negative = str_starts_with($raw, '!');
            $term = trim($raw, '!');
            if ($term === '') {
                continue;
            }
            $caseSensitive = (bool) preg_match('/[A-Z]/', $term);
            if ($negative) {
                $masks[] = new Term($term, $caseSensitive);
            } else {
                $terms[] = new Term($term, $caseSensitive);
            }
        }
        foreach (self::clean($properNouns) as $noun) {
            $terms[] = new Term($noun, caseSensitive: true);
        }
        foreach (self::clean($exceptions) as $phrase) {
            $masks[] = new Term($phrase);
        }
        foreach (self::clean($regularExpressions) as $expression) {
            $terms[] = new Term($expression, caseSensitive: true, wholeWord: false, regex: true);
        }

        return new self($terms, $masks);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function clean(array $values): array
    {
        return array_values(array_filter(array_map(trim(...), $values), static fn (string $v): bool => $v !== ''));
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }
}
