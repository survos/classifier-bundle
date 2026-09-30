<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Model;

/** A label on a document, and who put it there. */
final readonly class Assignment
{
    /** Assignments made by a person; no strategy may replace or remove them. */
    public const string EDITOR = 'editor';

    /** @param list<string> $evidence what the strategy saw: matched terms, source codes, ... */
    public function __construct(
        public string $vocabulary,
        public string $code,
        public string $strategy,
        public float $score = 1.0,
        public array $evidence = [],
    ) {}

    public function isEditorial(): bool
    {
        return $this->strategy === self::EDITOR;
    }
}
