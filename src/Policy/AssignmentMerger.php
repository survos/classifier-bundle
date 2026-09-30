<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Policy;

use Survos\ClassifierBundle\Model\Assignment;
use Survos\ClassifierBundle\Model\Result;

/** Applies a strategy's result to the assignments a document already has. */
final class AssignmentMerger
{
    /**
     * Editor assignments are never replaced or removed. A strategy's earlier assignments in the
     * same vocabulary are replaced by its new ones; an abstention changes nothing.
     *
     * @param list<Assignment> $existing
     * @return list<Assignment>
     */
    public function merge(array $existing, Result $result): array
    {
        if ($result->abstained !== null) {
            return $existing;
        }
        $kept = array_values(array_filter($existing, static fn (Assignment $a): bool => $a->isEditorial()
            || $a->vocabulary !== $result->vocabulary
            || $a->strategy !== $result->strategy));
        $editorial = [];
        foreach ($kept as $a) {
            if ($a->isEditorial() && $a->vocabulary === $result->vocabulary) {
                $editorial[$a->code] = true;
            }
        }
        foreach ($result->assignments as $assignment) {
            if (!isset($editorial[$assignment->code])) {
                $kept[] = $assignment;
            }
        }

        return $kept;
    }
}
