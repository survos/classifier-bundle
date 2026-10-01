<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\ClassifierBundle\Benchmark\Scorer;
use Survos\ClassifierBundle\Model\Assignment;
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Model\Result;
use Survos\ClassifierBundle\Policy\AssignmentMerger;
use Survos\ClassifierBundle\Strategy\RulesStrategy;
use Survos\ClassifierBundle\Strategy\TagVoteStrategy;
use Survos\ClassifierBundle\Vocabulary\ArrayVocabulary;
use Survos\ClassifierBundle\Vocabulary\CountriesByContinent;
use Survos\ClassifierBundle\Vocabulary\MediaTopicsVocabulary;
use Survos\ClassifierBundle\Vocabulary\UsMetroAreas;
use Symfony\Component\Intl\Countries;

final class ClassifierTest extends TestCase
{
    public function testMediaTopicsBecomeATreeVocabulary(): void
    {
        $topics = MediaTopicsVocabulary::create();
        self::assertTrue($topics->isTree());
        self::assertCount(17, $topics->roots());
        self::assertSame('health', $topics->get('07000000')?->label);
        self::assertNotEmpty($topics->children('07000000'));
        foreach ($topics->all() as $concept) {
            self::assertFalse($concept->retired);
        }
    }

    public function testEveryIntlCountryHasExactlyOneRegion(): void
    {
        $vocabulary = CountriesByContinent::load();
        self::assertCount(6, $vocabulary->roots());
        $countries = array_filter($vocabulary->all(), static fn ($c): bool => $c->parentCode !== null);
        $codes = array_map(static fn ($c): string => $c->code, $countries);
        sort($codes);
        self::assertSame(Countries::getCountryCodes(), $codes);
        self::assertSame('150', $vocabulary->get('FR')?->parentCode);
    }

    public function testMetroAreasMatchOnPrincipalCities(): void
    {
        $metros = UsMetroAreas::load();
        self::assertFalse($metros->isTree());
        self::assertCount(393, $metros->all());
        self::assertSame(['Dallas', 'Fort Worth', 'Arlington'], UsMetroAreas::cities('Dallas-Fort Worth-Arlington, TX'));
        self::assertSame(['Scranton', 'Wilkes-Barre'], UsMetroAreas::cities('Scranton--Wilkes-Barre, PA'));
        self::assertSame(['Louisville'], UsMetroAreas::cities('Louisville/Jefferson County, KY-IN'));

        $labels = array_map(
            static fn (string $code): string => $metros->get($code)->label,
            new RulesStrategy()->classify(new Document('1', 'Fort Worth bans flavored vapes'), $metros)->codes(),
        );
        self::assertSame(['Dallas-Fort Worth-Arlington, TX'], $labels);
    }

    public function testRulesRespectCaseWholeWordsAndExceptions(): void
    {
        $vocabulary = ArrayVocabulary::fromRows('topic', [
            ['code' => 'tobacco', 'triggerTerms' => ['tobacco', '!Alcohol, Tobacco and Firearms', '!Alcohol, Tobacco, Firearms']],
            ['code' => 'nebraska', 'triggerTerms' => ['Nebraska', 'NE']],
            ['code' => 'unruled'],
        ]);
        $classify = static fn (string $headline): array => new RulesStrategy()->classify(new Document('1', $headline), $vocabulary)->codes();

        self::assertSame(['tobacco'], $classify('Tobacco tax rises again'));
        self::assertSame([], $classify('Bureau of Alcohol, Tobacco and Firearms raids gun dealer'));
        self::assertSame(['tobacco'], $classify('Alcohol, Tobacco and Firearms agents seize smuggled tobacco'));
        self::assertSame([], $classify('Tobacconist opens; nobody in the northeast cares'));
        self::assertSame(['nebraska'], $classify('Omaha, NE bans vaping'));
    }

    public function testRulesAbstainWhenVocabularyHasNoRules(): void
    {
        $result = new RulesStrategy()->classify(new Document('1', 'Hospital opens'), MediaTopicsVocabulary::create());
        self::assertSame('vocabulary has no rules', $result->abstained);
    }

    public function testTagVoteAbstainsOnTieOrNoEvidence(): void
    {
        $topics = MediaTopicsVocabulary::create();
        $strategy = new TagVoteStrategy('topic', ['cancer' => '07000000', 'smoking' => '07000000', 'lawsuits' => '02000000']);
        $vote = static fn (array $tags): Result => $strategy->classify(new Document('1', 'x', labels: ['topic' => $tags]), $topics);

        self::assertSame(['07000000'], $vote(['cancer', 'smoking', 'lawsuits', 'unmapped'])->codes());
        self::assertSame('tie', $vote(['cancer', 'lawsuits'])->abstained);
        self::assertSame('no mapped labels', $vote(['unmapped'])->abstained);
    }

    public function testMergerNeverTouchesEditorAssignments(): void
    {
        $existing = [
            new Assignment('state', 'ohio', Assignment::EDITOR),
            new Assignment('state', 'iowa', RulesStrategy::NAME),
            new Assignment('topic', 'tax', RulesStrategy::NAME),
        ];
        $result = new Result('state', RulesStrategy::NAME, [
            new Assignment('state', 'ohio', RulesStrategy::NAME),
            new Assignment('state', 'utah', RulesStrategy::NAME),
        ]);
        $merged = array_map(static fn (Assignment $a): string => "$a->vocabulary:$a->code:$a->strategy", new AssignmentMerger()->merge($existing, $result));

        self::assertSame(['state:ohio:editor', 'topic:tax:rules', 'state:utah:rules'], $merged);
        self::assertSame($existing, new AssignmentMerger()->merge($existing, Result::abstain('state', RulesStrategy::NAME, 'unsure')));
    }

    public function testScorerCountsAbstentionsApart(): void
    {
        $scorer = new Scorer();
        $scorer->add(['a', 'b'], new Result('v', 's', [new Assignment('v', 'a', 's'), new Assignment('v', 'c', 's')]));
        $scorer->add(['a'], Result::abstain('v', 's', 'unsure'));
        $report = $scorer->report();

        self::assertSame([1, 1, 1], [$report['tp'], $report['fp'], $report['fn']]);
        self::assertSame([0.5, 0.5], [$report['precision'], $report['recall']]);
        self::assertSame([1, 1], [$report['abstained'], $report['goldLabelsInAbstained']]);
    }

    public function testScoresAddAndDropAndDecisiveRulesStopEverything(): void
    {
        $vocabulary = ArrayVocabulary::fromRows('project', [
            ['code' => 'tobacco', 'threshold' => 10, 'rules' => [
                ['match' => 'cigarette', 'score' => 6],
                ['match' => 'smoking', 'score' => 6],
                ['match' => 'smoking gun', 'score' => -8],
                ['regex' => 'R\\.?J\\.? Reynolds', 'scope' => 'headline', 'score' => 10],
                ['meta' => 'host', 'is' => 'tobaccoreporter.com', 'accept' => true],
                ['meta' => 'marking', 'is' => 'spam', 'reject' => true],
            ]],
        ]);
        $classify = static fn (Document $d): Result => new RulesStrategy()->classify($d, $vocabulary);
        $codes = static fn (Document $d): array => $classify($d)->codes();

        self::assertSame([], $codes(new Document('1', 'Cigarette sales fall')), 'one 6-point hit is under the threshold');
        self::assertSame(['tobacco'], $codes(new Document('1', 'Cigarette and smoking rates fall')));
        self::assertSame([], $codes(new Document('1', 'Cigarette sales and a smoking gun')), 'the penalty drops the total');
        self::assertSame(['tobacco'], $codes(new Document('1', 'R.J. Reynolds posts profit')));
        self::assertSame([], $codes(new Document('1', 'Weather', 'R.J. Reynolds posts profit')), 'scope: headline only');

        $accepted = $classify(new Document('1', 'Weather today', metadata: ['host' => 'tobaccoreporter.com']));
        self::assertSame(['tobacco'], $accepted->codes());
        self::assertTrue($accepted->assignments[0]->decisive);
        self::assertSame(['host=tobaccoreporter.com'], $accepted->assignments[0]->evidence);

        self::assertSame([], $codes(new Document('1', 'Cigarette and smoking rates fall', metadata: ['marking' => 'spam'])), 'reject settles it too');
    }

    public function testLegacyBangSuffixesStillMeanStrongAndAccept(): void
    {
        $vocabulary = ArrayVocabulary::fromRows('t', [['code' => 'a', 'triggerTerms' => ['iqos!!', 'vape!']]]);
        $strategy = new RulesStrategy();
        $hit = $strategy->classify(new Document('1', 'New iqos recall'), $vocabulary)->assignments[0];
        self::assertTrue($hit->decisive);
        self::assertSame(100.0, $strategy->classify(new Document('1', 'a vape shop'), $vocabulary)->assignments[0]->score);
    }
}
