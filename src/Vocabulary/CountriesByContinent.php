<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Vocabulary;

use Survos\ClassifierBundle\Model\Concept;
use Survos\ClassifierBundle\Model\RuleSet;
use Survos\ClassifierBundle\Model\Term;
use Symfony\Component\Intl\Countries;

/**
 * Countries (ISO 3166-1 alpha-2, names from symfony/intl) under the UN M49 regions.
 * Regions use their M49 numeric code, because two-letter continent codes collide with
 * countries (AF, AS, NA, SA). Taiwan is not in M49 and is placed in Asia.
 */
final class CountriesByContinent
{
    public const string NAME = 'country';

    private const array REGIONS = ['002' => 'Africa', '019' => 'Americas', '010' => 'Antarctica', '142' => 'Asia', '150' => 'Europe', '009' => 'Oceania'];

    private const array MEMBERS = [
        '002' => 'AO BF BI BJ BW CD CF CG CI CM CV DJ DZ EG EH ER ET GA GH GM GN GQ GW IO KE KM LR LS LY MA MG ML MR MU MW MZ NA NE NG RE RW SC SD SH SL SN SO SS ST SZ TD TF TG TN TZ UG YT ZA ZM ZW',
        '019' => 'AG AI AR AW BB BL BM BO BQ BR BS BV BZ CA CL CO CR CU CW DM DO EC FK GD GF GL GP GS GT GY HN HT JM KN KY LC MF MQ MS MX NI PA PE PM PR PY SR SV SX TC TT US UY VC VE VG VI',
        '010' => 'AQ',
        '142' => 'AE AF AM AZ BD BH BN BT CN CY GE HK ID IL IN IQ IR JO JP KG KH KP KR KW KZ LA LB LK MM MN MO MV MY NP OM PH PK PS QA SA SG SY TH TJ TL TM TR TW UZ VN YE',
        '150' => 'AD AL AT AX BA BE BG BY CH CZ DE DK EE ES FI FO FR GB GG GI GR HR HU IE IM IS IT JE LI LT LU LV MC MD ME MK MT NL NO PL PT RO RS RU SE SI SJ SK SM UA VA',
        '009' => 'AS AU CC CK CX FJ FM GU HM KI MH MP NC NF NR NU NZ PF PG PN PW SB TK TO TV UM VU WF WS',
    ];

    public static function load(string $locale = 'en'): ArrayVocabulary
    {
        $concepts = [];
        foreach (self::REGIONS as $code => $label) {
            $code = (string) $code; // PHP turns the key '142' into an int
            $concepts[] = new Concept($code, $label);
            foreach (explode(' ', self::MEMBERS[$code]) as $alpha2) {
                $name = Countries::getName($alpha2, $locale);
                $concepts[] = new Concept($alpha2, $name, parentCode: $code, rules: new RuleSet([new Term($name, caseSensitive: true)]));
            }
        }

        return new ArrayVocabulary(self::NAME, $concepts);
    }
}
