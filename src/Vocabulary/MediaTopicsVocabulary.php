<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Vocabulary;

use Survos\ClassifierBundle\Model\Concept;
use Survos\MediaTopics\MediaTopics;

/**
 * IPTC Media Topics as a classifier vocabulary, when survos/media-topics is installed.
 * Codes are the topic ids ("20000479"); retired topics are not offered.
 */
final class MediaTopicsVocabulary
{
    public const string NAME = 'media_topics';

    public static function create(?MediaTopics $topics = null, string $locale = MediaTopics::DEFAULT_LOCALE): ArrayVocabulary
    {
        $concepts = [];
        foreach ($topics ?? MediaTopics::load() as $topic) {
            $concepts[] = new Concept($topic->id, $topic->label($locale), $topic->definition($locale), $topic->parentId);
        }

        return new ArrayVocabulary(self::NAME, $concepts);
    }
}
