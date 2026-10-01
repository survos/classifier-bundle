# survos/classifier-bundle

Assign controlled labels to text. One set of vocabularies, several interchangeable ways of
assigning from them, and a benchmark that scores those ways against labeled data.

- A **vocabulary** says which labels exist: a flat list (US metro areas) or a tree (countries by region, IPTC Media Topics).
- A **strategy** says how labels get assigned: word rules, a vote over labels the text already has,
  or typed judgments from TypeSafe's Jev model.

Every assignment records the strategy that made it. Assignments made by an editor are never
replaced or removed. A strategy that can't tell abstains instead of guessing.

## Install

```bash
composer require survos/classifier-bundle
```

No configuration is needed for the rules strategies. For Jev, see [Jev](#jev-optional).
For IPTC Media Topics, `composer require survos/media-topics` (or `survos/media-topics-bundle`, whose
vocabulary instance is then shared).

## Vocabularies

| name | shape | source |
|---|---|---|
| `media_topics` | tree, 1,085 topics under 17 roots | IPTC Media Topics, from [`survos/media-topics`](https://packagist.org/packages/survos/media-topics); registered only when that package is installed |
| `country` | tree, 249 countries under 6 regions | names from `symfony/intl`, grouped by UN M49 region |
| `us_metro` | flat, 393 areas | Census Bureau metropolitan statistical areas, July 2023 |

Add your own by registering a service that implements `VocabularyInterface`; it is tagged
automatically. `ArrayVocabulary::fromRows()` builds one from plain rows, which is the easy way to
expose an app's tag table:

```php
use Survos\ClassifierBundle\Vocabulary\ArrayVocabulary;

$lawsuits = ArrayVocabulary::fromRows('lawsuit', [
    ['code' => 'engle', 'label' => 'Engle v. Liggett', 'properNouns' => ['Engle'],
     'definition' => 'The article is about the Engle class action or its progeny cases.'],
]);
```

Row keys: `code`, `label`, `definition`, `parent`, `triggerTerms`, `properNouns`, `exceptions`,
`regularExpressions`.

## Strategies

| name | how it decides |
|---|---|
| `rules` | trigger terms, proper nouns and regular expressions in the headline and summary |
| `rules_headline` | the same rules, headline only |
| `tag_vote` | maps labels the document already carries in another vocabulary; the single most-voted concept wins, a tie abstains. Not registered by default: define a `TagVoteStrategy` service with your code map |
| `jev` | TypeSafe's Jev model; registered only when `symfony/ai-type-safe-platform` is installed |

### Rules

Terms match whole words. A term containing an uppercase letter is case-sensitive (`NE` matches
"Omaha, NE", not "the northeast"); proper nouns always are.

Exceptions are phrases that contain a trigger without meaning it. They are blanked out of the text
before matching, so with the trigger `tobacco` and the exception `Alcohol, Tobacco and Firearms`,
a story about an ATF gun raid is not labeled tobacco, but an ATF story that also mentions smuggled
tobacco is. In `triggerTerms`, a leading `!` marks an exception.

### Scores, and rules that settle it

A concept can carry structured rules beside its term lists (`rules` in `ArrayVocabulary::fromRows()`,
or `RuleSet::fromRules()`). Matches add to a score; the concept is assigned when the total reaches its
`threshold` (default 1).

```php
ArrayVocabulary::fromRows('project', [['code' => 'tobacco', 'threshold' => 10, 'rules' => [
    ['match' => 'cigarette', 'score' => 6],                          // a hit adds 6
    ['match' => 'smoking gun', 'score' => -8],                       // a hit takes 8 away
    ['regex' => 'R\.?J\.? Reynolds', 'scope' => 'headline', 'score' => 10],
    ['except' => 'Alcohol, Tobacco and Firearms'],                   // blanked out before matching
    ['meta' => 'host', 'is' => 'tobaccoreporter.com', 'accept' => true],  // existing metadata: accept, nothing else consulted
    ['meta' => 'marking', 'is' => 'spam', 'reject' => true],         // ... or reject outright
]]]);
```

Options per rule: `score` (default 1, may be negative), `scope` (`headline`, `summary` or `body`;
default is what the strategy reads), `case` (default: case-sensitive iff the term has a capital),
`word` (whole words, default true). `meta` rules test `Document::$metadata`, which is whatever the
caller already knows about the item (host, source, marking, tag ids ...); a list value matches if it
contains the value. An `accept` rule, in text or metadata, assigns the concept with
`Assignment::$decisive = true` and stops evaluating it; `reject` withholds it. Decisive rules are
checked before any score is added, wherever they appear in the list.

The older term syntax still works: `term!` is worth 100, `term!!` accepts outright, `!term` is an exception.

### Jev (optional)

```bash
composer require symfony/ai-type-safe-platform
```

Set `TYPESAFE_API_KEY`, or point the bundle at a platform you already configured:

```yaml
# config/packages/survos_classifier.yaml
survos_classifier:
    jev:
        # platform: ai.platform.typesafe   # default: built from TYPESAFE_API_KEY
        model: jev-1.13.0                  # pinned, so tuned thresholds stay valid
        threshold: 0.5                     # minimum probability to assign a label
        beam: 3                            # paths kept while walking a tree
        min_path_score: 0.5
        min_separation: 1.2                # best path must beat a rival by this factor
        cache: cache.app                   # responses are cached; null to disable
        modes: { }                         # vocabulary => labels | walk
        questions: { }                     # vocabulary => yes/no question with {label}
```

Two modes, chosen per vocabulary:

- **labels** (default for flat vocabularies, and for `country`): one yes/no question per concept,
  sent together. Every concept at or above `threshold` is assigned. A concept's `definition` is
  sent as the meaning of "yes", so write one.
- **walk** (default for trees): finds one label. Starting at the roots, each step asks which child
  fits, with "none of these" as an option, keeping the best `beam` paths. It stops at a parent when
  no child fits, and abstains when the best path is weak or barely ahead of a different branch.

The default threshold of 0.5 assigns far too many labels. Tune it on your own labeled data with
the benchmark below; cached responses make rerunning at another threshold free.

## Commands

```bash
bin/console classifier:vocabularies
bin/console classifier:classify country "France raises cigarette prices"
bin/console classifier:benchmark labeled.jsonl state --concepts=tags.jsonl
```

All three take `--format=json`. The first two are also agent tools when
`survos/command-bundle` is installed.

### Benchmark

`classifier:benchmark <file> <vocabulary>` runs every strategy over labeled documents, one JSON
object per line:

```json
{"id": "1", "headline": "Ohio sues Juul", "summary": "...", "labels": {"state": ["ohio"]}}
```

`--concepts=tags.jsonl` loads the vocabulary from a file of rows (the keys above, plus
`vocabulary`) instead of the registry. Other options: `--strategy`, `--limit`, `--per-concept`,
`--price` (US dollars per million input tokens, default 0.042).

Per strategy it reports true and false positives, false negatives, precision, recall and F1 over
the documents the strategy answered; abstentions separately, with the gold labels they left
unassigned; milliseconds per document; and for Jev, requests, input tokens and cost. For trees it
also counts documents where the prediction is on the same branch as a gold label.

## In code

```php
use Survos\ClassifierBundle\Model\Document;
use Survos\ClassifierBundle\Policy\AssignmentMerger;
use Survos\ClassifierBundle\Strategy\RulesStrategy;
use Survos\ClassifierBundle\Vocabulary\VocabularyRegistry;

$result = $rules->classify(new Document($id, $headline, $summary), $registry->get('country'));

if ($result->abstained === null) {
    // Keeps editor assignments; replaces this strategy's earlier ones in this vocabulary.
    $assignments = $merger->merge($existingAssignments, $result);
}
```

## Development

```bash
composer install
vendor/bin/phpunit
bin/console classifier:vocabularies   # the bundle's commands, without a host app
```

`DESIGN.md` has the design notes and the first measurements against a news archive.
