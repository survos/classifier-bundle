<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Benchmark;

use Survos\ClassifierBundle\Model\Result;

/**
 * Accumulates one strategy's results against gold labels for one vocabulary.
 * Precision and recall cover the documents the strategy answered; abstentions are counted apart,
 * with the gold labels they left unassigned, so abstaining can't be mistaken for being right.
 */
final class Scorer
{
    /** @var array<string, array{tp: int, fp: int, fn: int}> */
    private array $perConcept = [];
    private int $documents = 0;
    private int $abstained = 0;
    private int $goldInAbstained = 0;
    private float $seconds = 0.0;
    /** @var array<string, int|float> */
    private array $usage = [];

    /** @param list<string> $gold */
    public function add(array $gold, Result $result, float $seconds = 0.0): void
    {
        ++$this->documents;
        $this->seconds += $seconds;
        foreach ($result->usage as $key => $value) {
            $this->usage[$key] = ($this->usage[$key] ?? 0) + $value;
        }
        if ($result->abstained !== null) {
            ++$this->abstained;
            $this->goldInAbstained += count($gold);

            return;
        }
        $predicted = array_unique($result->codes());
        foreach (array_intersect($predicted, $gold) as $code) {
            $this->bump($code, 'tp');
        }
        foreach (array_diff($predicted, $gold) as $code) {
            $this->bump($code, 'fp');
        }
        foreach (array_diff($gold, $predicted) as $code) {
            $this->bump($code, 'fn');
        }
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $total = ['tp' => 0, 'fp' => 0, 'fn' => 0];
        $concepts = [];
        ksort($this->perConcept);
        foreach ($this->perConcept as $code => $counts) {
            foreach ($counts as $key => $n) {
                $total[$key] += $n;
            }
            $concepts[$code] = $counts + self::rates($counts);
        }

        return [
            'documents' => $this->documents,
            'answered' => $this->documents - $this->abstained,
            'abstained' => $this->abstained,
            'goldLabelsInAbstained' => $this->goldInAbstained,
            ...$total,
            ...self::rates($total),
            'msPerDocument' => $this->documents ? round(1000 * $this->seconds / $this->documents, 3) : null,
            'usage' => $this->usage,
            'concepts' => $concepts,
        ];
    }

    private function bump(string $code, string $key): void
    {
        $this->perConcept[$code] ??= ['tp' => 0, 'fp' => 0, 'fn' => 0];
        ++$this->perConcept[$code][$key];
    }

    /**
     * @param array{tp: int, fp: int, fn: int} $c
     * @return array{precision: ?float, recall: ?float, f1: ?float}
     */
    private static function rates(array $c): array
    {
        $precision = $c['tp'] + $c['fp'] ? $c['tp'] / ($c['tp'] + $c['fp']) : null;
        $recall = $c['tp'] + $c['fn'] ? $c['tp'] / ($c['tp'] + $c['fn']) : null;
        $f1 = $precision && $recall ? 2 * $precision * $recall / ($precision + $recall) : null;

        return array_map(static fn (?float $v): ?float => $v === null ? null : round($v, 4), compact('precision', 'recall', 'f1'));
    }
}
