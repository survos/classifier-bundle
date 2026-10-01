<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Model;

/**
 * One rule: what to look for, and what a hit does.
 *
 * - `score` is added on a hit; a negative score takes points away ("drop the score").
 * - `decision` settles the concept outright, before any score is counted: `accept` assigns it
 *   (decisive, no more rules), `reject` withholds it.
 * - `scope` reads a different part of the document than the strategy's default (headline, summary, body).
 * - `meta` tests what the document already carries instead of its text: the named metadata key
 *   must equal (or contain, for a list) `pattern`. "If the existing metadata has this."
 */
final readonly class Term
{
    public const string ACCEPT = 'accept';
    public const string REJECT = 'reject';

    public function __construct(
        public string $pattern,
        public bool $caseSensitive = false,
        public bool $wholeWord = true,
        public bool $regex = false,
        public int $score = 1,
        public ?string $scope = null,
        public ?string $decision = null,
        public ?string $meta = null,
    ) {
        if ($decision !== null && $decision !== self::ACCEPT && $decision !== self::REJECT) {
            throw new \InvalidArgumentException(sprintf('Unknown decision "%s"; expected accept or reject.', $decision));
        }
    }

    public function toRegex(): string
    {
        $body = $this->regex ? str_replace('~', '\~', $this->pattern) : preg_quote($this->pattern, '~');
        if ($this->wholeWord) {
            // Lookarounds rather than \b, so terms that start or end with punctuation ("R.J.") still anchor.
            $body = '(?<![\p{L}\p{N}])(?:'.$body.')(?![\p{L}\p{N}])';
        }

        return '~'.$body.'~u'.($this->caseSensitive ? '' : 'i');
    }

    /** True when the document's metadata satisfies a `meta` rule. */
    public function matchesMetadata(Document $document): bool
    {
        $value = $document->metadata[$this->meta] ?? null;
        foreach (is_array($value) ? $value : [$value] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $candidate = (string) $candidate;
            if ($this->regex ? preg_match($this->toRegex(), $candidate) === 1 : strcasecmp($candidate, $this->pattern) === 0) {
                return true;
            }
        }

        return false;
    }
}
