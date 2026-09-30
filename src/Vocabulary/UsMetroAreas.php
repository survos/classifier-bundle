<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Vocabulary;

use Survos\ClassifierBundle\Model\Concept;
use Survos\ClassifierBundle\Model\RuleSet;
use Survos\ClassifierBundle\Model\Term;

/**
 * US metropolitan statistical areas (Census Bureau CBSA delineation, July 2023), keyed by CBSA code.
 * Rules are the principal city names from the title, case-sensitive. Names shared by several
 * metros (Columbus, Springfield, Portland) match all of them; the rules can't tell them apart.
 */
final class UsMetroAreas
{
    public const string NAME = 'us_metro';
    public const string PINNED_FILE = __DIR__.'/../../resources/us-metro-areas.json';

    private const array HYPHENATED_CITIES = ['Winston-Salem', 'Wilkes-Barre'];

    public static function load(string $file = self::PINNED_FILE): ArrayVocabulary
    {
        $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        $concepts = [];
        foreach ($data['areas'] as $area) {
            $concepts[] = new Concept(
                (string) $area['code'],
                $area['label'],
                sprintf('The article is about something happening in, or specifically concerning, the %s metropolitan area.', $area['label']),
                rules: new RuleSet(array_map(static fn (string $city): Term => new Term($city, caseSensitive: true), self::cities($area['label']))),
            );
        }

        return new ArrayVocabulary(self::NAME, $concepts);
    }

    /**
     * "Dallas-Fort Worth-Arlington, TX" gives Dallas, Fort Worth, Arlington.
     *
     * @return list<string>
     */
    public static function cities(string $title): array
    {
        $names = substr($title, 0, (int) strrpos($title, ', '));
        $cities = [];
        foreach (explode('--', $names) as $part) {
            $part = explode('/', $part)[0];
            array_push($cities, ...(in_array($part, self::HYPHENATED_CITIES, true) ? [$part] : explode('-', $part)));
        }

        return $cities;
    }
}
