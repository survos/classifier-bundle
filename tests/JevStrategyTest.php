<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\ClassifierBundle\Model\Concept;
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Model\Result;
use Survos\ClassifierBundle\Strategy\JevStrategy;
use Survos\ClassifierBundle\Vocabulary\ArrayVocabulary;
use Symfony\AI\Platform\Bridge\TypeSafe\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class JevStrategyTest extends TestCase
{
    /** @var list<array<string, mixed>> request bodies sent to the API */
    private array $requests = [];

    protected function setUp(): void
    {
        if (!class_exists(Factory::class)) {
            self::markTestSkipped('symfony/ai-type-safe-platform is not installed.');
        }
    }

    public function testLabelsModeAssignsEveryConceptAtOrAboveThreshold(): void
    {
        $states = new ArrayVocabulary('state', [new Concept('ohio', 'Ohio'), new Concept('utah', 'Utah'), new Concept('iowa', 'Iowa')]);
        $result = $this->classify($states, fn (array $q): array => array_map(
            static fn (array $question): array => ['type' => 'noul', 'noul' => match (true) {
                str_contains($question['instructions'], 'Ohio') => 0.97,
                str_contains($question['instructions'], 'Utah') => 0.45,
                default => 0.02,
            }],
            $q,
        ), questions: ['state' => 'Does this article concern the US state of {label}?'], threshold: 0.6);

        self::assertSame(['ohio'], $result->codes());
        self::assertSame(1, $result->usage['uncertainLabels']);
        self::assertSame([1, 100], [$result->usage['requests'], $result->usage['inputTokens']]);
        self::assertSame(['headline' => 'Ohio sues Juul'], $this->requests[0]['state']);
        self::assertSame('Does this article concern the US state of Ohio?', $this->requests[0]['questions']['ohio']['instructions']);
    }

    public function testWalkFollowsTheTreeAndStopsAtTheParentWhenNoChildFits(): void
    {
        $result = $this->classify($this->tree(), static fn (array $q): array => array_map(
            static fn (array $question): array => self::choice(match (true) {
                array_key_exists('health', $question['criteria']) => ['health' => 0.9, 'sport' => 0.05, 'none of these' => 0.05],
                array_key_exists('disease', $question['criteria']) => ['disease' => 0.2, 'healthcare' => 0.1, 'none of these' => 0.7],
                default => ['football' => 0.5, 'none of these' => 0.5],
            }),
            $q,
        ));

        self::assertSame(['health'], $result->codes());
        self::assertNull($result->abstained);
        // Second request asks about the children of both surviving roots at once.
        self::assertCount(2, $this->requests[1]['questions']);
    }

    public function testWalkGoesToTheLeaf(): void
    {
        $result = $this->classify($this->tree(), static fn (array $q): array => array_map(
            static fn (array $question): array => self::choice(match (true) {
                array_key_exists('health', $question['criteria']) => ['health' => 0.9, 'sport' => 0.05, 'none of these' => 0.05],
                array_key_exists('disease', $question['criteria']) => ['disease' => 0.85, 'healthcare' => 0.1, 'none of these' => 0.05],
                default => ['football' => 0.5, 'none of these' => 0.5],
            }),
            $q,
        ));

        self::assertSame(['disease'], $result->codes());
        self::assertSame(['health', 'disease'], $result->assignments[0]->evidence);
    }

    public function testWalkAbstainsWhenNothingFitsOrTwoBranchesTie(): void
    {
        $nothing = $this->classify($this->tree(), static fn (array $q): array => array_map(
            static fn (): array => self::choice(['health' => 0.1, 'sport' => 0.1, 'none of these' => 0.8]),
            $q,
        ), beam: 1);
        self::assertSame('none of the top-level concepts fits', $nothing->abstained);

        $tie = $this->classify($this->tree(), static fn (array $q): array => array_map(
            static fn (array $question): array => self::choice(array_key_exists('health', $question['criteria'])
                ? ['health' => 0.48, 'sport' => 0.47, 'none of these' => 0.05]
                : ['none of these' => 1.0] + array_fill_keys(array_keys($question['criteria']), 0.0)) ,
            $q,
        ));
        self::assertSame('two paths too close to call', $tie->abstained);
    }

    private function tree(): ArrayVocabulary
    {
        return new ArrayVocabulary('topic', [
            new Concept('health', 'health', 'Physical and mental well-being'),
            new Concept('disease', 'disease', parentCode: 'health'),
            new Concept('healthcare', 'healthcare', parentCode: 'health'),
            new Concept('sport', 'sport'),
            new Concept('football', 'football', parentCode: 'sport'),
        ]);
    }

    /**
     * @param array<string, float> $probabilities
     * @return array<string, mixed>
     */
    private static function choice(array $probabilities): array
    {
        arsort($probabilities);

        return ['type' => 'choice', 'choice' => array_key_first($probabilities), 'probabilities' => $probabilities, 'confidence' => 0.8];
    }

    /**
     * @param \Closure(array<string, array<string, mixed>>): array<string, array<string, mixed>> $answer
     * @param array<string, string> $questions
     */
    private function classify(ArrayVocabulary $vocabulary, \Closure $answer, array $questions = [], float $threshold = 0.5, int $beam = 3): Result
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): JsonMockResponse {
            $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            $this->requests[] = $body;
            $answers = $answer($body['questions']);

            return new JsonMockResponse(['model' => 'jev-1.13.0', 'answers' => $answers, 'usage' => ['input_tokens' => 100, 'output_tokens' => 5]]);
        });
        $strategy = new JevStrategy(Factory::createPlatform('test-key', $http), threshold: $threshold, beam: $beam, questions: $questions);

        return $strategy->classify(new Document('1', 'Ohio sues Juul'), $vocabulary);
    }
}
