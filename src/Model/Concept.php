<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Model;

/** One label in a vocabulary. */
final readonly class Concept
{
    public function __construct(
        public string $code,
        public string $label,
        public string $definition = '',
        public ?string $parentCode = null,
        public RuleSet $rules = new RuleSet(),
        public bool $retired = false,
    ) {}
}
