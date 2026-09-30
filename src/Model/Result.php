<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Model;

/**
 * What one strategy said about one document in one vocabulary.
 * An abstention ("I can't tell") is not the same as an empty answer ("none of these apply").
 */
final readonly class Result
{
    /**
     * @param list<Assignment> $assignments
     * @param array<string, int|float> $usage e.g. inputTokens, requests
     */
    public function __construct(
        public string $vocabulary,
        public string $strategy,
        public array $assignments = [],
        public ?string $abstained = null,
        public array $usage = [],
    ) {}

    public static function abstain(string $vocabulary, string $strategy, string $reason): self
    {
        return new self($vocabulary, $strategy, abstained: $reason);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (Assignment $a): string => $a->code, $this->assignments);
    }
}
