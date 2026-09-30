<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Command;

use Survos\ClassifierBundle\Benchmark\Scorer;
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Strategy\StrategyInterface;
use Survos\ClassifierBundle\Vocabulary\ArrayVocabulary;
use Survos\ClassifierBundle\Vocabulary\VocabularyInterface;
use Survos\ClassifierBundle\Vocabulary\VocabularyRegistry;
use Survos\CommandBundle\Attribute\AsAgentTool;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ClassifierCommands
{
    /** @var array<string, StrategyInterface> */
    private array $strategies = [];

    /** @param iterable<StrategyInterface> $strategies */
    public function __construct(
        private readonly VocabularyRegistry $vocabularies,
        iterable $strategies,
    ) {
        foreach ($strategies as $strategy) {
            $this->strategies[$strategy->name()] = $strategy;
        }
    }

    #[AsCommand('classifier:vocabularies', 'List the registered vocabularies and the available strategies')]
    #[AsAgentTool(readOnly: true)]
    public function vocabularies(
        SymfonyStyle $io,
        #[Option('Output format: text or json')] string $format = 'text',
    ): int {
        $rows = [];
        foreach ($this->vocabularies->all() as $name => $vocabulary) {
            $concepts = $vocabulary->all();
            $rows[] = [
                'name' => $name,
                'tree' => $vocabulary->isTree(),
                'concepts' => count($concepts),
                'roots' => count($vocabulary->roots()),
                'withRules' => count(array_filter($concepts, static fn ($c): bool => !$c->rules->isEmpty())),
            ];
        }
        if ($format === 'json') {
            $this->json($io, ['vocabularies' => $rows, 'strategies' => array_keys($this->strategies)]);
        } else {
            $io->table(['name', 'tree', 'concepts', 'roots', 'with rules'], array_map(
                static fn (array $r): array => [$r['name'], $r['tree'] ? 'yes' : 'no', $r['concepts'], $r['roots'], $r['withRules']],
                $rows,
            ));
            $io->writeln('Strategies: '.implode(', ', array_keys($this->strategies)));
        }

        return Command::SUCCESS;
    }

    #[AsCommand('classifier:classify', 'Classify one text against a vocabulary with every strategy, side by side')]
    #[AsAgentTool(readOnly: true)]
    public function classify(
        SymfonyStyle $io,
        #[Argument('Vocabulary name, see classifier:vocabularies')] string $vocabulary,
        #[Argument('Headline (or the whole text) to classify')] string $headline,
        #[Option('Summary or lead paragraph')] string $summary = '',
        #[Option('Only this strategy')] ?string $strategy = null,
        #[Option('Output format: text or json')] string $format = 'text',
    ): int {
        $vocab = $this->vocabularies->get($vocabulary);
        $document = new Document('cli', $headline, $summary);
        $out = [];
        foreach ($this->selectStrategies($strategy) as $name => $impl) {
            $result = $impl->classify($document, $vocab);
            $out[$name] = [
                'abstained' => $result->abstained,
                'assignments' => array_map(static fn ($a): array => [
                    'code' => $a->code,
                    'label' => $vocab->get($a->code)?->label,
                    'score' => $a->score,
                    'evidence' => $a->evidence,
                ], $result->assignments),
            ];
        }
        if ($format === 'json') {
            $this->json($io, $out);

            return Command::SUCCESS;
        }
        foreach ($out as $name => $result) {
            $io->section($name);
            if ($result['abstained'] !== null) {
                $io->writeln('abstained: '.$result['abstained']);
            } elseif ($result['assignments'] === []) {
                $io->writeln('no labels');
            } else {
                $io->table(['code', 'label', 'score', 'evidence'], array_map(
                    static fn (array $a): array => [$a['code'], $a['label'], $a['score'], implode(', ', $a['evidence'])],
                    $result['assignments'],
                ));
            }
        }

        return Command::SUCCESS;
    }

    #[AsCommand('classifier:benchmark', 'Score each strategy against a labeled JSONL set: precision, recall, abstentions, time')]
    public function benchmark(
        SymfonyStyle $io,
        #[Argument('Labeled documents, JSONL: {id, headline, summary, labels: {vocabulary: [codes]}}')] string $file,
        #[Argument('Vocabulary to score; also the key read from each document\'s labels')] string $vocabulary,
        #[Option('Load the vocabulary from this JSONL instead of the registry: {vocabulary, code, label, triggerTerms, ...}')] ?string $concepts = null,
        #[Option('Only this strategy')] ?string $strategy = null,
        #[Option('Stop after this many documents')] ?int $limit = null,
        #[Option('Include per-concept numbers')] bool $perConcept = false,
        #[Option('US dollars per million input tokens, for the cost column')] float $price = 0.042,
        #[Option('Output format: text or json')] string $format = 'text',
    ): int {
        $vocab = $concepts === null ? $this->vocabularies->get($vocabulary) : $this->vocabularyFromFile($vocabulary, $concepts);
        $strategies = $this->selectStrategies($strategy);
        $scorers = array_map(static fn (): Scorer => new Scorer(), $strategies);
        $samePath = array_fill_keys(array_keys($strategies), 0);

        $n = 0;
        foreach (JsonlReader::open($file) as $row) {
            if ($limit !== null && $n >= $limit) {
                break;
            }
            ++$n;
            $gold = array_map(strval(...), $row['labels'][$vocabulary] ?? []);
            // A strategy must not see the answer it is scored on; other vocabularies' labels stay visible.
            unset($row['labels'][$vocabulary]);
            $document = Document::fromArray($row);
            foreach ($strategies as $name => $impl) {
                $start = hrtime(true);
                $result = $impl->classify($document, $vocab);
                $scorers[$name]->add($gold, $result, (hrtime(true) - $start) / 1e9);
                if ($this->onSamePath($vocab, $result->codes(), $gold)) {
                    ++$samePath[$name];
                }
            }
        }

        $report = ['file' => basename($file), 'vocabulary' => $vocabulary, 'concepts' => count($vocab->all()), 'strategies' => []];
        foreach ($scorers as $name => $scorer) {
            $strategyReport = $scorer->report();
            if (!$perConcept) {
                unset($strategyReport['concepts']);
            }
            if ($vocab->isTree()) {
                // Documents where a predicted concept equals a gold one, or is its ancestor or descendant.
                $strategyReport['samePath'] = $samePath[$name];
            }
            if (isset($strategyReport['usage']['inputTokens'])) {
                $strategyReport['costUsd'] = round($strategyReport['usage']['inputTokens'] * $price / 1e6, 6);
            }
            $strategyReport['usage'] = (object) $strategyReport['usage']; // {} rather than [] when empty
            $report['strategies'][$name] = $strategyReport;
        }
        if ($format === 'json') {
            $this->json($io, $report);

            return Command::SUCCESS;
        }
        $io->title(sprintf('%s: %d documents, %d concepts', $vocabulary, $n, $report['concepts']));
        $io->table(
            ['strategy', 'answered', 'abstained', 'tp', 'fp', 'fn', 'precision', 'recall', 'f1', 'ms/doc', 'same path', 'cost $'],
            array_map(static fn (string $name, array $r): array => [
                $name, $r['answered'], $r['abstained'], $r['tp'], $r['fp'], $r['fn'],
                $r['precision'] ?? '-', $r['recall'] ?? '-', $r['f1'] ?? '-', $r['msPerDocument'], $r['samePath'] ?? '-', $r['costUsd'] ?? '-',
            ], array_keys($report['strategies']), $report['strategies']),
        );

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $predicted
     * @param list<string> $gold
     */
    private function onSamePath(VocabularyInterface $vocabulary, array $predicted, array $gold): bool
    {
        $lineage = static function (string $code) use ($vocabulary): array {
            for ($codes = []; $code !== null && !isset($codes[$code]); $code = $vocabulary->get($code)?->parentCode) {
                $codes[$code] = true;
            }

            return $codes;
        };
        foreach ($predicted as $p) {
            foreach ($gold as $g) {
                if (isset($lineage($p)[$g]) || isset($lineage($g)[$p])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function vocabularyFromFile(string $name, string $file): VocabularyInterface
    {
        $rows = [];
        foreach (JsonlReader::open($file) as $row) {
            if (($row['vocabulary'] ?? $name) === $name) {
                $rows[] = $row;
            }
        }

        return ArrayVocabulary::fromRows($name, $rows);
    }

    /** @return array<string, StrategyInterface> */
    private function selectStrategies(?string $only): array
    {
        if ($only === null) {
            return $this->strategies;
        }

        return [$only => $this->strategies[$only] ?? throw new \InvalidArgumentException(sprintf(
            'Unknown strategy "%s". Known: %s.', $only, implode(', ', array_keys($this->strategies)),
        ))];
    }

    /** @param array<string, mixed> $data */
    private function json(SymfonyStyle $io, array $data): void
    {
        $io->writeln(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }
}
