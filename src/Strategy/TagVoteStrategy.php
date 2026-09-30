<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Strategy;

use Survos\ClassifierBundle\Model\Assignment;
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Model\Result;
use Survos\ClassifierBundle\Vocabulary\VocabularyInterface;

/**
 * Derives one label from labels the document already carries in another vocabulary: each source
 * label votes for the concept it maps to, and the single most-voted concept wins. A tie, or no
 * mapped label at all, is an abstention.
 */
final class TagVoteStrategy implements StrategyInterface
{
    public const string NAME = 'tag_vote';

    /** @param array<string, string> $map source concept code => code in the vocabulary being classified */
    public function __construct(
        private readonly string $sourceVocabulary,
        private readonly array $map,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function classify(Document $document, VocabularyInterface $vocabulary): Result
    {
        $votes = [];
        foreach ($document->labels[$this->sourceVocabulary] ?? [] as $source) {
            $target = $this->map[$source] ?? null;
            if ($target !== null && $vocabulary->get($target) !== null) {
                $votes[$target][] = $source;
            }
        }
        if ($votes === []) {
            return Result::abstain($vocabulary->name(), self::NAME, 'no mapped labels');
        }
        $counts = array_map(count(...), $votes);
        $winners = array_keys($counts, max($counts), true);
        if (count($winners) !== 1) {
            return Result::abstain($vocabulary->name(), self::NAME, 'tie');
        }
        $code = (string) $winners[0];

        return new Result($vocabulary->name(), self::NAME, [
            new Assignment($vocabulary->name(), $code, self::NAME, $counts[$code] / array_sum($counts), $votes[$code]),
        ]);
    }
}
