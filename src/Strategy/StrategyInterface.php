<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Strategy;

use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Model\Result;
use Survos\ClassifierBundle\Vocabulary\VocabularyInterface;

/** How labels get assigned. Implementations abstain rather than guess. */
interface StrategyInterface
{
    /** Recorded on every assignment this strategy makes. */
    public function name(): string;

    public function classify(Document $document, VocabularyInterface $vocabulary): Result;
}
