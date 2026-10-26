<?php

/**
 * The arithmetic behind Tables: users from rows, then each of OPA's tables in OPA's row and column order. See
 * Tables for which visit each figure comes from.
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
use OpenHealthcare\TitleXReport\Reference\PovertyGuidelines;

final class TablesBuilder
{
    private const RACE_COLUMNS = ['race_amindian', 'race_asian', 'race_black', 'race_hpi', 'race_white'];
    private const SEXES = ['Female' => 0, 'Male' => 1];
    public const REGION_NAMES = ['contiguous' => 'the 48 contiguous states and DC', 'alaska' => 'Alaska', 'hawaii' => 'Hawaii'];

    /** @param array<string, mixed> $map data/fpar-tables.json */
    public function __construct(
        private readonly int $year,
        private readonly Fpar $fpar,
        private readonly array $map,
        private readonly PovertyGuidelines $guidelines,
        private readonly string $region,
    ) {
    }

    /**
     * @param list<Row> $rows written rows only
     * @return array<int, Table>
     */
    public function tables(array $rows): array
    {
        $users = $this->users($rows);
        return [
            1 => $this->table1($users),
            2 => $this->raceTable(2, 'Unduplicated number of female family planning users by race and ethnicity', $users, 0),
            3 => $this->raceTable(3, 'Unduplicated number of male family planning users by race and ethnicity', $users, 1),
            4 => $this->table4($users),
            5 => $this->oneColumn(5, 'Unduplicated number of family planning users by principal health insurance coverage status', $this->map['insurance']['rows'], $users, fn(array $u): int => $this->insuranceRow($u['latest'])),
            6 => $this->oneColumn(6, 'Unduplicated number of family planning users with limited English proficiency (LEP)', $this->map['lep']['rows'], $users, fn(array $u): int => $this->lepRow($u['latest'])),
            7 => $this->methodTable(7, 'Unduplicated number of female family planning users by primary method and age group', $users, 0, 'female'),
            8 => $this->methodTable(8, 'Unduplicated number of male family planning users by primary method and age group', $users, 1, 'male'),
            9 => $this->table9($rows, $users),
            11 => $this->table11($users),
            12 => $this->table12($rows),
        ];
    }

    /**
     * Each person once: their rows in visit order, their latest row, their sex (0 female, 1 male) and age.
     *
     * @param list<Row> $rows
     * @return list<array{rows: list<Row>, latest: Row, sex: int, age: int}>
     */
    private function users(array $rows): array
    {
        $byPatient = [];
        foreach ($rows as $row) {
            $byPatient[$row->cells['patient_id']][] = $row;
        }
        $users = [];
        foreach ($byPatient as $patientRows) {
            usort($patientRows, fn(Row $a, Row $b): int => strcmp($a->cells['visit_date'], $b->cells['visit_date']));
            $latest = $patientRows[count($patientRows) - 1];
            $sex = self::SEXES[$this->fpar->display('sex', $latest->cells['sex'])] ?? null;
            if ($sex === null) {
                continue; // sex is one of OPA's five keys; a row without it isn't written
            }
            $users[] = ['rows' => $patientRows, 'latest' => $latest, 'sex' => $sex, 'age' => $this->age($latest->cells['birth_date'])];
        }
        return $users;
    }

    /** Age as of June 30 of the reporting year, as every FPAR table counts it. */
    private function age(string $birthDate): int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthDate, $m) !== 1) {
            return 0;
        }
        $age = $this->year - (int)$m[1] - ("$m[2]-$m[3]" > '06-30' ? 1 : 0);
        return max(0, $age);
    }

    /** @param list<array{label: string, from: int, to: int}> $bands */
    private static function band(array $bands, int $age): int
    {
        foreach ($bands as $i => $b) {
            if ($age >= $b['from'] && $age <= $b['to']) {
                return $i;
            }
        }
        return count($bands) - 1;
    }

    /** @param list<array{rows: list<Row>, latest: Row, sex: int, age: int}> $users */
    private function table1(array $users): Table
    {
        $bands = $this->map['ageBands']['users'];
        $counts = array_fill(0, count($bands), [0, 0]);
        foreach ($users as $u) {
            $counts[self::band($bands, $u['age'])][$u['sex']]++;
        }
        return new Table(1, 'Unduplicated number of family planning users by age group and sex', ['Female users', 'Male users', 'Total users'], self::withTotals(
            array_map(fn(array $b, array $c): array => ['label' => $b['label'], 'cells' => $c], $bands, $counts),
            'Total users',
            true
        ));
    }

    /** @param list<array{rows: list<Row>, latest: Row, sex: int, age: int}> $users */
    private function raceTable(int $number, string $title, array $users, int $sex): Table
    {
        $labels = $this->map['race']['rows'];
        $counts = array_fill(0, count($labels), [0, 0, 0]);
        foreach ($users as $u) {
            if ($u['sex'] !== $sex) {
                continue;
            }
            $c = $u['latest']->cells;
            $races = [];
            foreach (self::RACE_COLUMNS as $column) {
                if ($c[$column] !== '' && strcasecmp($this->fpar->display($column, $c[$column]), 'No') !== 0) {
                    $races[] = $this->map['race']['columns'][$column];
                }
            }
            $row = match (count($races)) {
                0 => count($labels) - 1,      // unknown or not reported
                1 => $races[0],
                default => count($labels) - 2, // more than one race
            };
            $ethnicity = $this->map['race']['ethnicity'][$this->fpar->display('ethnicity', $c['ethnicity'])] ?? 2;
            $counts[$row][$ethnicity]++;
        }
        $total = $sex === 0 ? 'Total female users' : 'Total male users';
        return new Table($number, $title, ['Hispanic or Latino', 'Not Hispanic or Latino', 'Unknown/not reported', $total], self::withTotals(
            array_map(fn(string $l, array $c): array => ['label' => $l, 'cells' => $c], $labels, $counts),
            $total,
            true
        ));
    }

    /** @param list<array{rows: list<Row>, latest: Row, sex: int, age: int}> $users */
    private function table4(array $users): Table
    {
        $labels = $this->map['income']['rows'];
        $title = 'Unduplicated number of family planning users by income level';
        if (!$this->guidelines->has($this->year, $this->region)) {
            return new Table(4, $title, ['Number of users'], array_map(
                fn(string $l): array => ['label' => $l, 'cells' => [null]],
                [...$labels, 'Total users']
            ), sprintf('HHS poverty guidelines for %d (%s) are not loaded, so this table is not computed.', $this->year, self::REGION_NAMES[$this->region] ?? $this->region));
        }
        $counts = array_fill(0, count($labels), [0]);
        foreach ($users as $u) {
            $counts[$this->incomeRow($u['rows'])][0]++;
        }
        return new Table(4, $title, ['Number of users'], self::withTotals(
            array_map(fn(string $l, array $c): array => ['label' => $l, 'cells' => $c], $labels, $counts),
            'Total users',
            false
        ), sprintf('Income as a percentage of the HHS poverty guidelines for %d (%s): %s.', $this->year, self::REGION_NAMES[$this->region] ?? $this->region, $this->guidelines->source($this->year, $this->region)));
    }

    /**
     * The band of the latest visit with both income and household size, by whole percent of the guideline
     * (100.9 percent is "Below 101%"); unknown when no visit has both.
     *
     * @param list<Row> $rows in visit order
     */
    private function incomeRow(array $rows): int
    {
        $unknown = count($this->map['income']['rows']) - 1;
        foreach (array_reverse($rows) as $row) {
            $income = $row->cells['annual_household_income'];
            $size = $row->cells['household_size'];
            if ($income === '' || $size === '') {
                continue;
            }
            $guideline = $this->guidelines->guideline($this->year, $this->region, (int)$size);
            if ($guideline === null || $guideline <= 0) {
                return $unknown;
            }
            $percent = (int)floor((float)$income * 100 / $guideline);
            foreach ($this->map['income']['upTo'] as $i => $upTo) {
                if ($percent <= $upTo) {
                    return $i;
                }
            }
            return $unknown - 1; // above 250 percent
        }
        return $unknown;
    }

    private function insuranceRow(Row $latest): int
    {
        return match ($latest->cells['insurance_coverage_type']) {
            RowBuilder::COVERAGE_GOVERNMENT => 0,
            RowBuilder::COVERAGE_PRIVATE => 1,
            RowBuilder::COVERAGE_NONE => 2,
            default => 3,
        };
    }

    private function lepRow(Row $latest): int
    {
        return $this->map['lep']['names'][$this->fpar->display('limited_english_proficiency', $latest->cells['limited_english_proficiency'])] ?? 2;
    }

    /**
     * @param list<string>                                                      $labels
     * @param list<array{rows: list<Row>, latest: Row, sex: int, age: int}>     $users
     * @param callable(array{rows: list<Row>, latest: Row, sex: int, age: int}): int $rowOf
     */
    private function oneColumn(int $number, string $title, array $labels, array $users, callable $rowOf): Table
    {
        $counts = array_fill(0, count($labels), [0]);
        foreach ($users as $u) {
            $counts[$rowOf($u)][0]++;
        }
        return new Table($number, $title, ['Number of users'], self::withTotals(
            array_map(fn(string $l, array $c): array => ['label' => $l, 'cells' => $c], $labels, $counts),
            'Total users',
            false
        ));
    }

    /** @param list<array{rows: list<Row>, latest: Row, sex: int, age: int}> $users */
    private function methodTable(int $number, string $title, array $users, int $sex, string $which): Table
    {
        $methodRows = $this->map['methods'][$which];
        $bands = $this->map['ageBands']['users'];
        $counts = array_fill(0, count($methodRows), array_fill(0, count($bands), 0));
        foreach ($users as $u) {
            if ($u['sex'] === $sex) {
                $counts[$this->methodRow($u['rows'], $methodRows)][self::band($bands, $u['age'])]++;
            }
        }
        $total = $sex === 0 ? 'Total female users' : 'Total male users';
        return new Table($number, $title, [...array_column($bands, 'label'), $total], self::withTotals(
            array_map(fn(array $m, array $c): array => ['label' => $m['label'], 'cells' => $c], $methodRows, $counts),
            $total,
            true
        ));
    }

    /**
     * The row of a user's primary method: their latest visit with a method at exit, else their latest reason for
     * no method, else unknown. A method of "no method" goes by that visit's reason and pregnancy status.
     *
     * @param list<Row>                                                                         $rows in visit order
     * @param list<array{label: string, names: list<string>, noMethodReason?: string, unknown?: bool}> $methodRows
     */
    private function methodRow(array $rows, array $methodRows): int
    {
        $methods = $this->map['methods'];
        $unknown = count($methodRows) - 1;
        foreach (array_reverse($rows) as $row) {
            $code = $row->cells['contraceptive_method_exit'];
            if ($code === '') {
                continue;
            }
            $name = strtolower($this->fpar->display('contraceptive_method_exit', $code));
            if (in_array($name, array_map('strtolower', $methods['noMethod']), true)) {
                return $this->noMethodRow($row, $methodRows);
            }
            foreach ($methodRows as $i => $m) {
                if (in_array($name, array_map('strtolower', $m['names']), true)) {
                    return $i;
                }
            }
            return $unknown;
        }
        foreach (array_reverse($rows) as $row) {
            if ($row->cells['no_contraceptive_reason_exit'] !== '') {
                return $this->noMethodRow($row, $methodRows);
            }
        }
        return $unknown;
    }

    /** @param list<array{label: string, names: list<string>, noMethodReason?: string, unknown?: bool}> $methodRows */
    private function noMethodRow(Row $row, array $methodRows): int
    {
        $methods = $this->map['methods'];
        $reason = strtolower($this->fpar->display('no_contraceptive_reason_exit', $row->cells['no_contraceptive_reason_exit']));
        $pregnancy = strtolower($this->fpar->display('pregnancy_status', $row->cells['pregnancy_status']));
        $kind = match (true) {
            in_array($pregnancy, array_map('strtolower', $methods['pregnant']), true),
            in_array($reason, array_map('strtolower', $methods['reasons']['pregnant']), true) => 'pregnant',
            in_array($reason, array_map('strtolower', $methods['reasons']['abstinence']), true) => 'abstinence',
            default => 'other',
        };
        foreach ($methodRows as $i => $m) {
            if (($m['noMethodReason'] ?? null) === $kind) {
                return $i;
            }
        }
        return count($methodRows) - 1;
    }

    /**
     * @param list<Row>                                                    $rows
     * @param list<array{rows: list<Row>, latest: Row, sex: int, age: int}> $users
     */
    private function table9(array $rows, array $users): Table
    {
        $womenTested = 0;
        foreach ($users as $u) {
            if ($u['sex'] === 0 && array_filter($u['rows'], fn(Row $r): bool => $r->cells['test_pap'] !== '') !== []) {
                $womenTested++;
            }
        }
        $tests = $asc = $hsil = 0;
        foreach ($rows as $row) {
            $tests += $row->cells['test_pap'] !== '' ? 1 : 0;
            $result = strtolower($this->fpar->display('test_pap_result', $row->cells['test_pap_result']));
            $asc += $this->startsWithAny($result, $this->map['pap']['ascOrHigher']) ? 1 : 0;
            $hsil += $this->startsWithAny($result, $this->map['pap']['hsilOrHigher']) ? 1 : 0;
        }
        return new Table(9, 'Cervical cancer screening activities', ['Number of female users or number of tests'], [
            ['label' => 'Unduplicated number of female users who obtained a Pap test', 'cells' => [$womenTested]],
            ['label' => 'Number of Pap tests performed', 'cells' => [$tests]],
            ['label' => 'Number of Pap tests with an ASC or higher result', 'cells' => [$asc]],
            ['label' => 'Number of Pap tests with an HSIL or higher result', 'cells' => [$hsil]],
        ]);
    }

    /** @param list<string> $starts */
    private function startsWithAny(string $name, array $starts): bool
    {
        if ($name === '') {
            return false;
        }
        foreach ($starts as $start) {
            if (str_starts_with($name, strtolower($start))) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{rows: list<Row>, latest: Row, sex: int, age: int}> $users */
    private function table11(array $users): Table
    {
        $bands = $this->map['ageBands']['chlamydia'];
        $counts = array_fill(0, count($bands), [0, 0]);
        foreach ($users as $u) {
            if (array_filter($u['rows'], fn(Row $r): bool => $r->cells['test_chlamydia'] !== '') !== []) {
                $counts[self::band($bands, $u['age'])][$u['sex']]++;
            }
        }
        return new Table(11, 'Unduplicated number of family planning users tested for chlamydia by age group and sex', ['Female users', 'Male users'], self::withTotals(
            array_map(fn(array $b, array $c): array => ['label' => $b['label'], 'cells' => $c], $bands, $counts),
            'Total users',
            false
        ));
    }

    /** @param list<Row> $rows */
    private function table12(array $rows): Table
    {
        $counts = ['test_gonorrhea' => [0, 0], 'test_syphilis' => [0, 0], 'test_hiv' => [0, 0]];
        $hivPositive = 0;
        $positive = array_map('strtolower', $this->map['hivPositive']);
        foreach ($rows as $row) {
            $sex = self::SEXES[$this->fpar->display('sex', $row->cells['sex'])] ?? null;
            if ($sex === null) {
                continue;
            }
            foreach (array_keys($counts) as $column) {
                $counts[$column][$sex] += $row->cells[$column] !== '' ? 1 : 0;
            }
            $hivPositive += in_array(strtolower($this->fpar->display('test_hiv_result', $row->cells['test_hiv_result'])), $positive, true) ? 1 : 0;
        }
        $line = fn(string $label, array $c): array => ['label' => $label, 'cells' => [$c[0], $c[1], $c[0] + $c[1]]];
        return new Table(12, 'Number of tests for gonorrhea, syphilis, and HIV and number of positive confidential HIV tests', ['Female tests', 'Male tests', 'Total tests'], [
            $line('Gonorrhea', $counts['test_gonorrhea']),
            $line('Syphilis', $counts['test_syphilis']),
            $line('HIV: all confidential tests', $counts['test_hiv']),
            ['label' => 'HIV: positive confidential tests', 'cells' => [null, null, $hivPositive]],
            ['label' => 'HIV: anonymous tests', 'cells' => [null, null, null]],
        ], 'Anonymous HIV tests are not in the record; enter them in the portal. Positive tests count every result read as positive, reactive or detected, so a preliminary rapid result counts until a confirmed one replaces it.');
    }

    /**
     * The rows, a total row under them, and when asked a total column at the right.
     *
     * @param list<array{label: string, cells: list<int>}> $rows
     * @return list<array{label: string, cells: list<?int>}>
     */
    private static function withTotals(array $rows, string $totalLabel, bool $totalColumn): array
    {
        if ($totalColumn) {
            $rows = array_map(fn(array $r): array => ['label' => $r['label'], 'cells' => [...$r['cells'], array_sum($r['cells'])]], $rows);
        }
        $width = count($rows[0]['cells'] ?? []);
        $total = [];
        for ($c = 0; $c < $width; $c++) {
            $total[] = array_sum(array_map(fn(array $r): int => (int)$r['cells'][$c], $rows));
        }
        return [...$rows, ['label' => $totalLabel, 'cells' => $total]];
    }
}
