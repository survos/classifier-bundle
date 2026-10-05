# survos/classifier-bundle — design

> 2026-09-30: IPTC Media Topics moved out to `survos/media-topics` (library) and
> `survos/media-topics-bundle`, on the 2026-07-02 release. The measurements below used the 2021-05-05
> release that was bundled here at the time, under the vocabulary name `iptc` (now `media_topics`).

Assign controlled labels to text with two interchangeable strategies over the same
vocabularies, and measure them against each other. The benchmark numbers are the article.

## 1. What I found (read before the design)

**Ground truth in news' DB** (462,636 articles; all `marking=confirmed` locally, the N4 marking lives in `import_provenance`):

| N4 marking | articles | tagged | with IPTC topic | avg tags |
|---|---|---|---|---|
| approved (tobacco) | 41,400 | 37,825 | 18,850 | 5.1 |
| promoted (tobacco) | 427,456 | 70,596 | 30,230 | 0.6 |
| marijuana + rapp | 131 | 35 | 6 | — |

Assignments by category: topic 300,598 (97,755 articles), state 47,186, country 38,410,
organization 27,394, lawsuit 14,419 (2,838 articles).

Evidence on "editor-reviewed or regex output?" — it is mixed, and I can't settle it from the data:

- *Against pure headline regex:* for approved articles, the tagged state's name appears in the
  headline only 24% of the time (45% in headline + summary); country 16% / 31%; organization 33% / 45%.
  News' current `autoTag()` matches the headline only, so most of these did not come from it.
- *For some automation:* 84% of approved articles whose headline names one of ten sampled states carry a state tag
  (25% for promoted). Lawsuit-tagged articles carry ~5 lawsuit tags each, and several lawsuit tags
  have the single trigger `["lawsuit"]` (FDASuit, ACLUSuit) — that looks like a rule firing, not an editor.
- *Confound:* `media_tag` / `site_tag` exist. If N4 copied the outlet's state onto its articles, that label
  is not recoverable from the text by any strategy and will depress recall for both.

**Input text is short.** Only 31 articles have full text; 459,632 have a summary (avg 544 chars; headline avg 55).
The benchmark classifies headline + summary.

**The rules engine today** (`Tag::createScoringRulesFromTerms` + `StoryService::autoTag`): trigger terms only;
`!term` = exclusion (score −100), `term!` = always fetch (100), `term!!` = auto-accept (1000); case-sensitive iff the
term contains an uppercase letter; whole-word; headline scope. `properNouns`, `exceptions`, `regularExpressions`,
`scoringRules` and `defaultScore` are stored but never consulted by `autoTag`. 410 of 470 tags have trigger terms;
only 29 have an `iptcTopicCode`, so the tag-vote strategy can reach at most 29 tags' worth of topics.

**Jev from PHP already exists:** `symfony/ai-type-safe-platform` (in `ai-pipeline-demo/vendor`) provides
`Evaluation`, `ChoiceQuestion`, `NoulQuestion`, `ScoreQuestion`, typed `Answers`, a token-usage extractor and
models `jev-latest` / `jev-1.13.0`. No Survos bundle uses it yet. Jev 1.13: $0.042 per million input tokens,
output free, 64k tokens per request, max 255 options per Choice, 40 requests/s.

**Tac's answer (2026-09-30):** approved and promoted articles were both tagged by hand. "Approved" = belongs in
the project; "promoted" = a highlight. The hard case is a trigger inside another name: "Alcohol, Tobacco and
Firearms" must not tag an article as tobacco. Whether N4 copied outlet tags onto articles is still unknown.

**First rules run** (3,000 tagged articles, seeded by `md5(id)`, news' own trigger terms, 2026-09-30):

| vocabulary | concepts | headline only (what news did) P / R | headline + summary P / R |
|---|---|---|---|
| state | 53 | 0.96 / 0.19 | 0.60 / 0.41 |
| country | 182 | 0.93 / 0.37 | 0.52 / 0.56 |
| organization | 45 | 0.97 / 0.59 | 0.59 / 0.77 |
| lawsuit | 32 | 0.54 / 0.88 | 0.21 / 0.89 |
| topic | 124 | 0.94 / 0.52 | 0.44 / 0.66 |

Two readings, and the data can't separate them: (a) the rules are precise but blind to most of what editors
tagged; (b) editors started from the headline tagger's suggestions, so its precision against these labels is
partly circular. The lawsuit row (88% recall from rules whose trigger is often just "lawsuit") points at (b)
for that category. The extra hits from reading the summary count as false positives here, but some are surely
labels an editor never added. A blind hand check of disagreements is needed before these go in the article.

## 2. Shape

Namespace `Survos\ClassifierBundle`, extends `Survos\Kit\AbstractSurvosBundle`. The core is plain PHP with no
Doctrine: apps keep their own entities and feed the bundle through small interfaces.

```
Model/      Concept (code, label, definition, parentCode, RuleSet)   Document (id, headline, summary, body)
            Assignment (vocabulary, code, strategy, score, evidence) Result (assignments[], abstained, usage)
Vocabulary/ VocabularyInterface (name, isTree, roots(), children(code), get(code), all())
            VocabularyRegistry (tagged services; apps add their own, e.g. lawsuits from Doctrine)
            IptcMediaTopics, CountriesByContinent, UsMetroAreas, ArrayVocabulary
Strategy/   StrategyInterface::classify(Document, VocabularyInterface): Result
            RulesStrategy, JevStrategy, TagVoteStrategy
Policy/     AssignmentMerger — applies a Result onto existing assignments
Benchmark/  LabeledSet (JSONL), Scorer, BenchmarkService (the commands)
```

### Vocabularies
- **IPTC Media Topics** (tree): ship the pinned `cptall-en-US.json` (2021-05-05); parser lifted from news'
  `TopicsService::importTopics` (stable codes, single parent enforced, cycle check). Read-only in the bundle;
  the Doctrine upsert stays in the app.
- **Countries → continents** (tree): country names and codes from `symfony/intl`. Intl has no continent
  grouping as far as I know, so the bundle ships a small UN M49 map; I'll verify before writing it.
- **US metro areas** (flat): the 393 metropolitan statistical areas from the Census CBSA delineation (July 2023);
  rules generated from principal city names.
- **Lawsuits** (flat, open-ended): app-provided. Each concept carries parties, court, docket and aliases
  ("the Bragg case") — used as proper nouns by Rules and as criteria text by Jev. Adding a case is adding a row.

### Strategies
- **Rules** — the current trigger-term semantics, plus the fields news stores but ignores: proper nouns
  (case-sensitive), exceptions, regular expressions, scope, a per-term score and a threshold. Exceptions (and
  `!term`) blank the phrase out of the text before matching, so "Alcohol, Tobacco and Firearms" alone doesn't
  trigger tobacco but the same article mentioning tobacco elsewhere still does. Registered twice: `rules`
  (headline + summary) and `rules_headline` (what news did).
- **Jev** — registered only when `Symfony\AI\Platform\Bridge\TypeSafe\Evaluation` exists and a platform is wired.
  - *Tree vocabularies:* one Choice per node over its children (label + definition as criteria) plus an explicit
    "none of these" option. Beam search per the TypeSafe hierarchical-classification cookbook: keep K paths, all
    frontier nodes in one request, path score = geometric mean of edge probabilities. Stop at the parent when
    the children's distribution is flat; abstain when the best path is below threshold or not separated from the second.
  - *Flat, multi-label vocabularies* (states, organizations, lawsuits): one Noul per label, batched into one request
    over the same state. The threshold is tuned on a dev split, never on the test split.
  - State is `{headline, summary}` as named JSON fields. Model pinned to `jev-1.13.0` so thresholds stay valid.
    Responses cached by request hash, so reruns are free and the published numbers are reproducible.
- **Tag vote** — `PrimaryTopicGuesser::choose` generalized: a derived strategy that maps another vocabulary's
  assignments through a code map and abstains on a tie.

### Shared rules
Every `Assignment` names its strategy. `AssignmentMerger` never replaces or removes an assignment whose strategy
is `editor`. A strategy returns an abstention (with the reason) rather than a low-confidence label.

## 3. Benchmark

```
classifier:vocabularies                      list registered vocabularies and concept counts
classifier:classify <vocabulary> <text>      one document, all strategies side by side
classifier:benchmark <labeled.jsonl>         --vocabulary= --strategy= --limit= --seed= --format=json
```
Method-level `#[AsCommand]` on the services, `#[AsAgentTool]` on the read-only ones. The labeled set is JSONL
(`{id, headline, summary, labels: {state: [...], lawsuit: [...]}}`) so the benchmark doesn't depend on news' schema.

Reported per vocabulary and strategy: precision, recall, F1 (micro and per concept), abstentions counted separately
from errors, latency p50/p95, and for Jev requests, input tokens and cost per article from the API's `usage`.
Fixed seed, fixed split, output includes the model version and vocabulary version.

**Ground truth:** the hand-assigned N4 tags, on tagged articles only (an untagged article is not evidence that
no label applies). Seeded sample, thresholds tuned on a separate split. Because of the circularity risk above,
add a blind adjudication set: ~200 articles where rules and Jev disagree with each other or with N4, shown
without saying who said what. Outlet-derived state tags, if N4 made them, get reported separately.

## 4. What adopters would change (not doing this now)

**news:** `Tag` implements a concept adapter (trigger terms, proper nouns, exceptions → `RuleSet`);
`StoryService::autoTag` delegates to `RulesStrategy`; `PrimaryTopicGuesser` becomes `TagVoteStrategy`;
`TopicsService` uses the bundle's IPTC parser and drops its copy of `cptall-en-US.json`; assignments gain a
strategy column (today `tag_ids` is a bare id list, so "who assigned this" is unrecorded); add a command that
exports the labeled JSONL.

**tree-demo:** its `Topic` differs from news' only in API Platform filters and tree-bundle traits; it would load
topics through the bundle's IPTC parser instead of its own import. Entity unchanged.

## 5. First Jev run (2026-09-30)

Same seeded sample, first 300 articles, headline + summary, `jev-1.13.0`, one yes/no question per label with a
one-sentence definition. Thresholds were swept on these same 300 articles, so treat them as indicative, not final.

| vocabulary | headline rules P / R | headline + summary rules P / R | Jev P / R (threshold) |
|---|---|---|---|
| state | 0.96 / 0.18 | 0.56 / 0.41 | 0.87 / 0.63 (0.85) |
| organization | 0.98 / 0.61 | 0.51 / 0.79 | 0.65 / 0.43 (0.85); 0.49 / 0.70 (0.5) |
| topic | 0.94 / 0.51 | 0.44 / 0.66 | 0.55 / 0.38 (0.85); 0.38 / 0.59 (0.7) |
| country | 0.98 / 0.45 | 0.50 / 0.60 | 0.27 / 0.67 (0.85) |
| lawsuit | 0.44 / 0.92 | 0.29 / 0.92 | 0.50 / 0.01 (0.85) |

- **State** is the clean win: three and a half times the headline tagger's recall at nearly its precision.
- **Country:** 155 of Jev's 188 false positives are "USA". Editors didn't tag US stories as USA; Jev does.
  Without that one label Jev is at roughly 0.68 precision. This is a labeling convention, not a model error.
- **Lawsuit:** the gold labels are rule output (a generic lawsuit story carries three or more case tags), so the
  rules score 0.92 recall against themselves and Jev, which declines to name a case, scores near zero. This
  category needs hand labels before it can be measured at all.
- **Organization / topic:** Jev does not beat the rules here with one-line definitions built from the tag name.
  Many tag names are project shorthand; real definitions per tag are the obvious next thing to try.
- **IPTC tree walk** (300 articles with a `topic_id`): 227 answered, 73 abstained; 14% exact match, 40% on the
  same branch as the gold topic. The gold topic is mostly "tobacco", "health" or "healthcare policy" (it looks
  derived from tags, not chosen by an editor), so this measures agreement with a coarse label, not accuracy.
- **Cost and speed:** ~3,400 input tokens and one request per article for 53 state questions: $0.00014 and
  ~220 ms. 182 countries: two requests, $0.00038, ~500 ms. Tree walk: 3.7 requests, $0.00016, ~750 ms.
  Rules: 0.02–0.3 ms, free.

## 6. Status (2026-10-05)

**Paused.** The vocabularies come first: `survos/media-topics` (IPTC Media Topics 2026-07-02 and IPTC
Genre 2024-02-13) and `survos/media-topics-bundle` are released (2.34.22) and on Packagist, and tree-demo
loads its topic tree from the bundle. Classification resumes on top of them.

Built here: models; country, US metro and (optional) Media Topics vocabularies; rules, tag-vote and Jev
strategies; merger; scorer; three commands; README; 15 tests. The mono root stays on symfony/ai 0.13
(moving to 0.14 pulls in bookmark-bundle, which needs `owner_class` configured), so the Jev tests run only
against this bundle's own `vendor/`.

Next, in order:
1. Candidate retrieval as the main Media Topics path: shortlist 10–20 topics (search engine over label,
   definition and ancestor labels), then one Jev Choice over the shortlist; keep the tree walk as a fallback.
   Benchmark both on the same articles.
2. Primary vs secondary topic, plus a Genre question asked over the same state in the same request.
3. Store provenance with each classification: concept URI, role, score, strategy, model, vocabulary version.
4. Re-import news with Media Topics replacing its 124 bespoke topic tags; that needs either a tag→topic
   mapping or reclassification, and a hand-checked set to measure it.
5. Benchmark hygiene: separate tuning and test splits, a blind adjudication set, per-tag definitions.

Open questions for news' labels:
1. Did editors tag from scratch, or accept/extend the headline tagger's suggestions?
2. Did N4 copy outlet tags onto articles?
3. Should "USA" be excluded from the country benchmark, given editors didn't apply it to domestic stories?
