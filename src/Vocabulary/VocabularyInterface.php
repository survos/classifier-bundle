<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Vocabulary;

use Survos\ClassifierBundle\Model\Concept;

/** Which labels exist. Flat vocabularies simply have only roots. */
interface VocabularyInterface
{
    public function name(): string;

    public function isTree(): bool;

    public function get(string $code): ?Concept;

    /** @return list<Concept> every assignable (non-retired) concept */
    public function all(): array;

    /** @return list<Concept> */
    public function roots(): array;

    /** @return list<Concept> */
    public function children(string $code): array;
}
