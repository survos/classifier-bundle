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

    /** What "term!" is worth: enough to outweigh any ordinary penalty. */
    public const int STRONG = 100;

    /**
     * Structured rules, as an app would store them as JSON. Each rule is one of:
     *
     *   {"match": "Reynolds", "score": 10}                       add 10 when the word is found
     *   {"match": "Reynolds Road", "score": -10}                 take 10 away
     *   {"regex": "R\\.?J\\.? Reynolds", "scope": "headline"}    a regular expression, headline only
     *   {"meta": "host", "is": "tobaccoreporter.com", "accept": true}   existing metadata: accept outright, no more rules
     *   {"meta": "marking", "is": "spam", "reject": true}        ... or reject outright
     *   {"except": "Alcohol, Tobacco and Firearms"}              blank this phrase out before matching
     *
     * Options: "case" (true/false, default: case-sensitive iff the term has a capital), "word" (whole words,
     * default true), "scope" (headline|summary|body), "score" (default 1), and a top-level "threshold".
     *
     * @param list<array<string, mixed>> $rules
     */
    public static function fromRules(array $rules, int $threshold = 1): self
    {
        $terms = $masks = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;  // tolerate the [""] that old rows hold
            }
            $decision = !empty($rule['accept']) ? Term::ACCEPT : (!empty($rule['reject']) ? Term::REJECT : null);
            if (isset($rule['except'])) {
                $masks[] = new Term((string) $rule['except'], (bool) ($rule['case'] ?? false), scope: $rule['scope'] ?? null);
                continue;
            }
            if (isset($rule['meta'])) {
                $terms[] = new Term((string) ($rule['is'] ?? $rule['match'] ?? ''), false, false, isset($rule['regex']),
                    (int) ($rule['score'] ?? 1), decision: $decision, meta: (string) $rule['meta']);
                continue;
            }
            $pattern = (string) ($rule['regex'] ?? $rule['match'] ?? '');
            if ($pattern === '') {
                continue;
            }
            $regex = isset($rule['regex']);
            $terms[] = new Term(
                $pattern,
                (bool) ($rule['case'] ?? ($regex || preg_match('/[A-Z]/', $pattern))),
                (bool) ($rule['word'] ?? !$regex),
                $regex,
                (int) ($rule['score'] ?? 1),
                $rule['scope'] ?? null,
                $decision,
            );
        }

        return new self($terms, $masks, $threshold);
    }

    /** Both kinds together: the simple term lists, then structured rules on top. */
    public function with(self $other): self
    {
        return new self([...$this->terms, ...$other->terms], [...$this->exceptions, ...$other->exceptions], $other->threshold);
    }

    /**
     * Reads news' Tag columns. Trigger-term syntax: "term!" is a strong trigger (worth STRONG),
     * "term!!" accepts outright, "!term" is an exception; a term containing an uppercase letter is case-sensitive.
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
            } elseif (str_ends_with($raw, '!!')) {
                $terms[] = new Term($term, $caseSensitive, score: self::STRONG, decision: Term::ACCEPT);
            } elseif (str_ends_with($raw, '!')) {
                $terms[] = new Term($term, $caseSensitive, score: self::STRONG);
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
