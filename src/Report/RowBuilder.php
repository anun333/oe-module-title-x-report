<?php

/**
 * One FPAR 2.0 row from one encounter, by the rules in docs/products/title-x/fpar2-mapping-2026-10.md (the
 * openhealthcare workspace). Codes come from data/fpar2.json by column or by OPA's name for them; data/ holds
 * our own two maps (race groups, provider roles). A row missing one of the five keys OPA requires is refused
 * (the upload would reject the file); other problems are listed for the clinic to fix, and the row is kept.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

use OpenHealthcare\TitleXReport\Reference\Fpar;

final class RowBuilder
{
    public const KEYS = ['facility_id', 'patient_id', 'visit_date', 'birth_date', 'sex'];
    private const NOT_A_LANGUAGE = ['', 'decline_to_specify', 'declne_to_specfy'];
    private const ENGLISH = 'english';

    /** @var array<string, string> race group => column */
    private array $raceColumns;
    /** @var list<array{group: string, ranges: list<array{from: int, to: int}>}> */
    private array $raceGroups;
    /** @var array{hispanic: string, notHispanic: string, from: int, to: int} */
    private array $ethnicity;
    /** @var list<array{prefixes: list<string>, role: string}> */
    private array $roleRules;
    private string $otherRole;
    private LabTests $labs;

    public function __construct(private readonly Fpar $fpar, ?string $dataDir = null)
    {
        $dataDir ??= dirname(__DIR__, 2) . '/data';
        $race = json_decode((string)file_get_contents("$dataDir/race-groups.json"), true, flags: JSON_THROW_ON_ERROR);
        $this->raceColumns = $race['columns'];
        $this->raceGroups = array_map(fn(array $g): array => ['group' => $g['group'], 'ranges' => array_map(
            fn(array $r): array => ['from' => (int)$r['from'], 'to' => (int)$r['to']],
            $g['codeRanges']
        )], $race['raceGroups']);
        $this->ethnicity = ['hispanic' => $race['ethnicity']['hispanic'], 'notHispanic' => $race['ethnicity']['notHispanic'],
            'from' => (int)$race['ethnicity']['originCodes']['from'], 'to' => (int)$race['ethnicity']['originCodes']['to']];
        $roles = json_decode((string)file_get_contents("$dataDir/provider-roles.json"), true, flags: JSON_THROW_ON_ERROR);
        $this->roleRules = $roles['rules'];
        $this->otherRole = $roles['other'];
        $this->labs = new LabTests($fpar);
    }

    public function build(Visit $v): Row
    {
        $cells = array_fill_keys($this->fpar->columns(), '');
        $toFix = [];

        $cells['facility_id'] = $v->facilityNpi !== '' ? $v->facilityNpi : ($v->facilityId > 0 ? "facility-{$v->facilityId}" : '');
        $cells['provider_id'] = $v->providerNpi;
        $cells['provider_role'] = $this->role($v->providerTaxonomy);
        $cells['patient_id'] = $v->pubpid !== '' ? $v->pubpid : "pid-{$v->pid}";
        $cells['visit_date'] = self::date($v->visitDate);
        $cells['birth_date'] = self::date($v->birthDate);
        $cells['sex'] = match (strtolower($v->sex)) {
            'female' => 'F',
            'male' => 'M',
            default => '',
        };
        $cells['limited_english_proficiency'] = $this->limitedEnglish($v);
        $cells['ethnicity'] = $this->ethnicity($v->ethnicityCode);
        $cells = [...$cells, ...$this->race($v->raceCodes)];
        [$cells['annual_household_income'], $cells['household_size']] = $this->income($v);
        [$cells['insurance_coverage_type'], $cells['visit_payer'], $insuranceProblem] = $this->insurance($v->insurance);
        if ($insuranceProblem !== null) {
            $toFix[] = $insuranceProblem;
        }

        if ($v->visitRecord === null) {
            if ($v->titleXCategory) {
                $toFix[] = ['key' => Problems::NO_VISIT_RECORD, 'args' => []];
            }
        } else {
            foreach ($v->visitRecord as $question => $answer) {
                $column = $this->fpar->columnForQuestion($question);
                if ($column === null) {
                    continue;
                }
                if ($this->fpar->valid($column, $answer)) {
                    $cells[$column] = $answer;
                } else {
                    $toFix[] = ['key' => Problems::INVALID_ANSWER, 'args' => [$answer, $column]];
                }
            }
            $toFix = [...$toFix, ...$this->contradictions($cells)];
        }

        if ($v->vitals !== null) {
            $cells['blood_pressure_systolic'] = self::positiveInt($v->vitals['bps']);
            $cells['blood_pressure_diastolic'] = self::positiveInt($v->vitals['bpd']);
            if (($v->vitals['height'] ?? 0) > 0) {
                $cells['height'] = number_format((float)$v->vitals['height'], 1, '.', '');
                $cells['height_unit'] = 'in_i'; // OpenEMR stores inches
            }
            if (($v->vitals['weight'] ?? 0) > 0) {
                $cells['weight'] = number_format((float)$v->vitals['weight'], 1, '.', '');
                $cells['weight_unit'] = 'lb_av'; // OpenEMR stores pounds
            }
        }
        $cells['tobacco_smoking_status'] = $this->smoking($v->smoking);
        $cells = [...$cells, ...$this->labs->columns($v->orderCodes, $v->results)];

        $missing = array_values(array_filter(self::KEYS, fn(string $k): bool => $cells[$k] === ''));
        return new Row($v->encounter, $v->pid, $cells, $missing, $toFix);
    }

    private function role(string $taxonomy): string
    {
        $taxonomy = strtoupper(trim($taxonomy));
        if ($taxonomy === '') {
            return '';
        }
        foreach ($this->roleRules as $rule) {
            foreach ($rule['prefixes'] as $prefix) {
                if (str_starts_with($taxonomy, $prefix)) {
                    return $this->fpar->codeByName('provider_role', $rule['role']) ?? '';
                }
            }
        }
        return $this->fpar->codeByName('provider_role', $this->otherRole) ?? '';
    }

    /** The rule UDS uses for Table 3B, except that no language at all is unknown here, not English. */
    private function limitedEnglish(Visit $v): string
    {
        $language = strtolower(trim($v->language));
        if (strtolower(trim($v->interpreterNeeded)) === 'yes' || !in_array($language, [...self::NOT_A_LANGUAGE, self::ENGLISH], true)) {
            return $this->fpar->codeByName('limited_english_proficiency', 'Not proficient in English') ?? '';
        }
        return $language === self::ENGLISH ? ($this->fpar->codeByName('limited_english_proficiency', 'Proficient in English') ?? '') : '';
    }

    private function ethnicity(?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        if ($code === $this->ethnicity['hispanic'] || $code === $this->ethnicity['notHispanic']) {
            return $code;
        }
        $n = (int)substr($code, 0, 4);
        return $n >= $this->ethnicity['from'] && $n <= $this->ethnicity['to'] ? $this->ethnicity['hispanic'] : '';
    }

    /**
     * Each race column: its CDC code when the patient has a race in that group, else OPA's "No"; race_unknown
     * says whether any race is known. No known race leaves the five blank and race_unknown "Yes".
     *
     * @param list<string> $codes
     * @return array<string, string>
     */
    private function race(array $codes): array
    {
        $groups = [];
        foreach ($codes as $code) {
            $n = (int)substr($code, 0, 4);
            foreach ($this->raceGroups as $g) {
                foreach ($g['ranges'] as $r) {
                    if ($n >= $r['from'] && $n <= $r['to']) {
                        $groups[$g['group']] = true;
                    }
                }
            }
        }
        $out = [];
        foreach ($this->raceColumns as $group => $column) {
            $yes = $this->fpar->codes($column, 'urn:oid:2.16.840.1.113883.6.238')[0] ?? '';
            $out[$column] = $groups === [] ? '' : (isset($groups[$group]) ? $yes : ($this->fpar->codeByName($column, 'No') ?? ''));
        }
        $out['race_unknown'] = $this->fpar->codeByName('race_unknown', $groups === [] ? 'Yes' : 'No') ?? '';
        return $out;
    }

    /**
     * Sliding Fee's assessment in force on the visit date (a minor's own resources when assessed that way, as
     * Title X allows); else the patient's monthly income times twelve. Blank when declined or unknown.
     *
     * @return array{string, string}
     */
    private function income(Visit $v): array
    {
        if ($v->assessment !== null) {
            if ($v->assessment['declined']) {
                return ['', ''];
            }
            $income = $v->assessment['annualIncome'];
            $size = $v->assessment['familySize'];
            return [$income !== null && $income >= 0 ? (string)(int)round($income) : '', $size !== null && $size > 0 ? (string)$size : ''];
        }
        $income = $v->monthlyIncome !== null && $v->monthlyIncome >= 0 ? (string)(int)round($v->monthlyIncome * 12) : '';
        return [$income, $v->familySize !== null && $v->familySize > 0 ? (string)$v->familySize : ''];
    }

    /**
     * Data elements 13 and 14 from the primary insurance's Source of Payment Typology code. OPA's current coverage
     * types are SOP 3 (government), 5 (private) and 8 (no payment); the first digit places a payer in one of them
     * (1 to 4 government, 5 to 7 private, 8 none). Our reading of OPA's mapping guidance, which maps HL7 codes.
     *
     * @param array{sop: string}|null $insurance
     * @return array{string, string, array{key: string, args: list<string>}|null}
     */
    private function insurance(?array $insurance): array
    {
        if ($insurance === null) {
            return ['8', $this->fpar->valid('visit_payer', '81') ? '81' : '', null]; // no coverage on the visit date: self-pay
        }
        $sop = trim($insurance['sop']);
        if ($sop === '') {
            return ['', '', ['key' => Problems::NO_PAYER_CODE, 'args' => []]];
        }
        $type = match ($sop[0]) {
            '1', '2', '3', '4' => '3',
            '5', '6', '7' => '5',
            '8' => '8',
            default => '',
        };
        return [$type, $this->fpar->valid('visit_payer', $sop) ? $sop : '', $type === '' ? ['key' => Problems::UNKNOWN_COVERAGE, 'args' => [$sop]] : null];
    }

    /** OPA's current code when OpenEMR's is one, else OPA's transition code with the same name as the list entry. */
    private function smoking(?array $smoking): string
    {
        if ($smoking === null) {
            return '';
        }
        $code = preg_replace('/^SNOMED-CT:/', '', $smoking['code']) ?? '';
        if ($code !== '' && $this->fpar->current('tobacco_smoking_status', $code)) {
            return $code;
        }
        return $this->fpar->codeByName('tobacco_smoking_status', $smoking['title']) ?? '';
    }

    /**
     * Answers that contradict each other, from OPA's data quality checks.
     *
     * @param array<string, string> $c
     * @return list<array{key: string, args: list<string>}>
     */
    private function contradictions(array $c): array
    {
        $out = [];
        if ($c['contraceptive_method_exit'] !== '' && $c['contraceptive_method_provided'] === '') {
            $out[] = ['key' => Problems::METHOD_NOT_PROVIDED, 'args' => []];
        }
        foreach (['intake', 'exit'] as $when) {
            if ($c["contraceptive_method_$when"] !== '' && $c["no_contraceptive_reason_$when"] !== '') {
                $out[] = ['key' => $when === 'intake' ? Problems::METHOD_AND_REASON_INTAKE : Problems::METHOD_AND_REASON_EXIT, 'args' => []];
            }
        }
        return $out;
    }

    private static function date(string $value): string
    {
        $value = substr(trim($value), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && $value !== '0000-00-00' ? $value : '';
    }

    private static function positiveInt(mixed $value): string
    {
        return is_numeric($value) && (float)$value > 0 ? (string)(int)round((float)$value) : '';
    }
}
