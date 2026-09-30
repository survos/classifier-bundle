<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        $this->application = new Application(new TestKernel('test', true));
    }

    public function testVocabulariesAreRegistered(): void
    {
        $out = $this->runJson('classifier:vocabularies', []);
        self::assertSame(['rules', 'rules_headline'], $out['strategies']);
        $byName = array_column($out['vocabularies'], null, 'name');
        self::assertSame(17, $byName['media_topics']['roots']);
        self::assertSame(249, $byName['country']['withRules']);
    }

    public function testClassifyCountry(): void
    {
        $out = $this->runJson('classifier:classify', ['vocabulary' => 'country', 'headline' => 'France raises cigarette prices']);
        self::assertSame(['FR'], array_column($out['rules']['assignments'], 'code'));
    }

    public function testBenchmarkScoresAgainstGoldLabels(): void
    {
        $dir = sys_get_temp_dir().'/survos-classifier-bundle';
        @mkdir($dir, recursive: true);
        file_put_contents($docs = $dir.'/docs.jsonl', implode("\n", array_map(json_encode(...), [
            ['id' => '1', 'headline' => 'Ohio sues Juul', 'labels' => ['state' => ['ohio']]],
            ['id' => '2', 'headline' => 'Smoking ban in Columbus', 'labels' => ['state' => ['ohio']]],
            ['id' => '3', 'headline' => 'Utah and Ohio settle', 'labels' => ['state' => ['ohio']]],
        ]))."\n");
        file_put_contents($concepts = $dir.'/concepts.jsonl', implode("\n", array_map(json_encode(...), [
            ['vocabulary' => 'state', 'code' => 'ohio', 'triggerTerms' => ['Ohio']],
            ['vocabulary' => 'state', 'code' => 'utah', 'triggerTerms' => ['Utah']],
            ['vocabulary' => 'topic', 'code' => 'ban', 'triggerTerms' => ['ban']],
        ]))."\n");

        $rules = $this->runJson('classifier:benchmark', ['file' => $docs, 'vocabulary' => 'state', '--concepts' => $concepts])['strategies']['rules'];
        self::assertSame([2, 1, 1], [$rules['tp'], $rules['fp'], $rules['fn']]);
        self::assertSame(0, $rules['abstained']);
    }

    /**
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    private function runJson(string $command, array $input): array
    {
        $tester = new CommandTester($this->application->find($command));
        $tester->execute($input + ['--format' => 'json']);
        $tester->assertCommandIsSuccessful();

        return json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
