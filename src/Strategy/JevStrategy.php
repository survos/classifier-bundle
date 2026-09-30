<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Strategy;

use Survos\ClassifierBundle\Model\Assignment;
use Survos\ClassifierBundle\Model\Concept;
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Model\Result;
use Survos\ClassifierBundle\Vocabulary\VocabularyInterface;
use Symfony\AI\Platform\Bridge\TypeSafe\Answer\Answers;
use Symfony\AI\Platform\Bridge\TypeSafe\Evaluation;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\ChoiceQuestion;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\NoulQuestion;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\QuestionInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Asks TypeSafe's Jev model for typed judgments instead of matching words.
 *
 * Two modes, chosen per vocabulary:
 *  - labels: one yes/no question per concept, all sent together; every concept whose probability
 *    reaches the threshold is assigned. For vocabularies where several labels can apply.
 *  - walk:   for trees where one label is wanted. A beam search from the roots: each node on the
 *    beam is one multiple-choice question over its children plus "none of these", and a path's
 *    score is the geometric mean of the probabilities along it. Abstains when the best path is
 *    weak or barely ahead of a rival path.
 */
final class JevStrategy implements StrategyInterface
{
    public const string NAME = 'jev';
    public const string MODE_LABELS = 'labels';
    public const string MODE_WALK = 'walk';

    private const string NONE = 'none of these';
    private const string DEFAULT_QUESTION = 'Should this news article be labeled "{label}"?';

    /**
     * @param array<string, string> $modes     vocabulary name => MODE_*; default: walk for trees, labels otherwise
     * @param array<string, string> $questions vocabulary name => yes/no question with a {label} placeholder
     */
    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly string $model = 'jev-1.13.0',
        private readonly float $threshold = 0.5,
        private readonly int $beam = 3,
        private readonly float $minPathScore = 0.5,
        private readonly float $minSeparation = 1.2,
        private readonly array $modes = [],
        private readonly array $questions = [],
        private readonly int $questionsPerRequest = 120,
        private readonly ?CacheInterface $cache = null,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function classify(Document $document, VocabularyInterface $vocabulary): Result
    {
        $state = array_filter(['headline' => $document->headline, 'summary' => $document->summary, 'body' => $document->body], static fn (string $t): bool => $t !== '');
        $mode = $this->modes[$vocabulary->name()] ?? ($vocabulary->isTree() ? self::MODE_WALK : self::MODE_LABELS);
        $usage = ['requests' => 0, 'cacheHits' => 0, 'inputTokens' => 0];

        return $mode === self::MODE_WALK
            ? $this->walk($state, $vocabulary, $usage)
            : $this->labels($state, $vocabulary, $usage);
    }

    /**
     * @param array<string, string> $state
     * @param array<string, int|float> $usage
     */
    private function labels(array $state, VocabularyInterface $vocabulary, array $usage): Result
    {
        $template = $this->questions[$vocabulary->name()] ?? self::DEFAULT_QUESTION;
        $questions = [];
        foreach ($vocabulary->all() as $concept) {
            // In a tree, only the leaves are labels; their parents are groupings.
            if ($vocabulary->children($concept->code) !== []) {
                continue;
            }
            $questions[$concept->code] = new NoulQuestion(
                str_replace('{label}', $concept->label, $template),
                $concept->definition !== '' ? $concept->definition : null,
            );
        }
        if ($questions === []) {
            return Result::abstain($vocabulary->name(), self::NAME, 'vocabulary has no concepts');
        }

        $assignments = [];
        $uncertain = 0;
        foreach (array_chunk($questions, $this->questionsPerRequest, true) as $chunk) {
            $answers = $this->ask($state, $chunk, $usage);
            foreach (array_keys($chunk) as $code) {
                $p = $answers->getNoul((string) $code)->getProbability();
                if ($p >= $this->threshold) {
                    $assignments[] = new Assignment($vocabulary->name(), (string) $code, self::NAME, $p);
                } elseif ($p > 1 - $this->threshold) {
                    ++$uncertain;
                }
            }
        }

        return new Result($vocabulary->name(), self::NAME, $assignments, usage: $usage + ['uncertainLabels' => $uncertain]);
    }

    /**
     * @param array<string, string> $state
     * @param array<string, int|float> $usage
     */
    private function walk(array $state, VocabularyInterface $vocabulary, array $usage): Result
    {
        // A path: the codes chosen so far, the probability of each step, and whether it can go deeper.
        $open = [['codes' => [], 'probs' => []]];
        $closed = [];
        while ($open !== []) {
            $questions = $options = [];
            foreach ($open as $i => $path) {
                $children = $path['codes'] === [] ? $vocabulary->roots() : $vocabulary->children((string) end($path['codes']));
                $criteria = [];
                foreach ($children as $child) {
                    $options[$i][$child->label] = $child->code;
                    $criteria[$child->label] = $child->definition !== '' ? $child->definition : null;
                }
                $parent = $path['codes'] === [] ? null : $vocabulary->get((string) end($path['codes']));
                $criteria[self::NONE] = $parent === null
                    ? 'The article fits none of these topics, or there is too little text to tell.'
                    : sprintf('The article is about "%s" in general, or none of these narrower topics fits it.', $parent->label);
                $questions['q'.$i] = new ChoiceQuestion(
                    $parent === null
                        ? 'Which one of these topics is the main subject of this news article?'
                        : sprintf('The article\'s main subject is within "%s". Which one of these narrower topics is it?', $parent->label),
                    $criteria,
                );
            }
            $answers = $this->ask($state, $questions, $usage);

            $candidates = [];
            foreach ($open as $i => $path) {
                foreach ($answers->getChoice('q'.$i)->getProbabilities() as $option => $p) {
                    $next = ['codes' => $path['codes'], 'probs' => [...$path['probs'], $p]];
                    if ($option === self::NONE) {
                        $next['done'] = true;
                    } else {
                        $next['codes'][] = $options[$i][$option] ?? throw new \UnexpectedValueException(sprintf('Jev answered with an option that was not offered: "%s".', $option));
                        $next['done'] = $vocabulary->children($options[$i][$option]) === [];
                    }
                    $candidates[] = $next + ['score' => self::geometricMean($next['probs'])];
                }
            }
            // Finished paths compete with the ones still growing, so a deep path has to keep earning its place.
            $candidates = [...$candidates, ...$closed];
            usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $candidates = array_slice($candidates, 0, $this->beam);
            $closed = array_values(array_filter($candidates, static fn (array $c): bool => $c['done']));
            $open = array_values(array_filter($candidates, static fn (array $c): bool => !$c['done']));
        }

        $best = $closed[0];
        if ($best['codes'] === []) {
            return new Result($vocabulary->name(), self::NAME, abstained: 'none of the top-level concepts fits', usage: $usage);
        }
        if ($best['score'] < $this->minPathScore) {
            return new Result($vocabulary->name(), self::NAME, abstained: 'best path too weak', usage: $usage);
        }
        // A rival is a path that disagrees, not the same path stopped one level higher or lower.
        foreach (array_slice($closed, 1) as $rival) {
            $shared = min(count($rival['codes']), count($best['codes']));
            $sameBranch = $shared > 0 && array_slice($rival['codes'], 0, $shared) === array_slice($best['codes'], 0, $shared);
            if (!$sameBranch && $rival['score'] > 0 && $best['score'] / $rival['score'] < $this->minSeparation) {
                return new Result($vocabulary->name(), self::NAME, abstained: 'two paths too close to call', usage: $usage);
            }
        }
        $labels = array_map(static fn (string $code): string => $vocabulary->get($code)->label ?? $code, $best['codes']);

        return new Result($vocabulary->name(), self::NAME, [
            new Assignment($vocabulary->name(), (string) end($best['codes']), self::NAME, $best['score'], $labels),
        ], usage: $usage);
    }

    /**
     * @param array<string, string> $state
     * @param array<string, QuestionInterface> $questions
     * @param array<string, int|float> $usage
     */
    private function ask(array $state, array $questions, array &$usage): Answers
    {
        $evaluation = new Evaluation($state, $questions);
        $call = function () use ($evaluation): array {
            $deferred = $this->platform->invoke($this->model, $evaluation);
            $answers = $deferred->asObject();
            if (!$answers instanceof Answers) {
                throw new \UnexpectedValueException('Jev returned '.get_debug_type($answers).' instead of answers.');
            }
            $tokens = $deferred->getResult()->getMetadata()->get('token_usage')?->getPromptTokens() ?? 0;

            return ['answers' => $answers, 'inputTokens' => $tokens];
        };

        if ($this->cache === null) {
            $response = $call();
            ++$usage['requests'];
        } else {
            $miss = false;
            $key = 'jev.'.hash('xxh128', $this->model.json_encode($evaluation, \JSON_THROW_ON_ERROR));
            $response = $this->cache->get($key, static function () use ($call, &$miss): array {
                $miss = true;

                return $call();
            });
            ++$usage[$miss ? 'requests' : 'cacheHits'];
        }
        // Tokens are counted on a cache hit too: the report states what the run costs, not what this rerun cost.
        $usage['inputTokens'] += $response['inputTokens'];

        return $response['answers'];
    }

    /** @param list<float> $probabilities */
    private static function geometricMean(array $probabilities): float
    {
        return exp(array_sum(array_map(static fn (float $p): float => log(max($p, 1e-9)), $probabilities)) / count($probabilities));
    }
}
