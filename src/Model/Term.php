<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Model;

/** One trigger: a literal phrase (or a regular expression body) and what a hit is worth. */
final readonly class Term
{
    public function __construct(
        public string $pattern,
        public bool $caseSensitive = false,
        public bool $wholeWord = true,
        public bool $regex = false,
        public int $score = 1,
    ) {}

    public function toRegex(): string
    {
        $body = $this->regex ? str_replace('~', '\~', $this->pattern) : preg_quote($this->pattern, '~');
        if ($this->wholeWord) {
            // Lookarounds rather than \b, so terms that start or end with punctuation ("R.J.") still anchor.
            $body = '(?<![\p{L}\p{N}])(?:'.$body.')(?![\p{L}\p{N}])';
        }

        return '~'.$body.'~u'.($this->caseSensitive ? '' : 'i');
    }
}
