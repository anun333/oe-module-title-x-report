<?php

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Tests;

use OpenHealthcare\TitleXReport\Reference\Fpar;
use OpenHealthcare\TitleXReport\Reference\PovertyGuidelines;
use OpenHealthcare\TitleXReport\Report\Row;
use OpenHealthcare\TitleXReport\Report\RowBuilder;
use OpenHealthcare\TitleXReport\Report\Tables;
use PHPUnit\Framework\TestCase;

/**
 * OPA's tables as the preview builds them (FPAR 2.0 Implementation Guide, September 2025, Appendix A), from
 * rows made up here, with codes looked up by OPA's names in data/fpar2.json.
 */
final class TablesTest extends TestCase
{
    private const YEAR = 2026;

    private static Fpar $fpar;
    private static PovertyGuidelines $guidelines;

    public static function setUpBeforeClass(): void
    {
        self::$fpar = Fpar::load();
        self::$guidelines = PovertyGuidelines::load();
    }

    public function testEveryMethodAndReasonOpaListsHasARowInBothMethodTables(): void
    {
        $map = Tables::loadMap();
        $data = json_decode((string)file_get_contents(dirname(__DIR__) . '/data/fpar2.json'), true);
        $names = static fn(string $column): array => array_values(array_unique(array_map(
            fn(array $v): string => strtolower(Fpar::plainName($v['display'])),
            array_values(array_filter($data['elements'], fn(array $e): bool => $e['column'] === $column))[0]['values']
        )));
        $exit = $names('contraceptive_method_exit');
        self::assertGreaterThan(35, count($exit), 'the reference file was read');
        foreach (['female', 'male'] as $which) {
            $placed = array_map('strtolower', [...$map['methods']['noMethod'], ...array_merge(...array_column($map['methods'][$which], 'names'))]);
            self::assertSame([], array_values(array_diff($exit, $placed)), "methods with no row in the $which table");
            self::assertSame([], array_values(array_diff($placed, $exit)), "names in the $which table that OPA doesn't list");
        }
        $reasons = array_map('strtolower', array_merge(...array_values($map['methods']['reasons'])));
        self::assertSame([], array_values(array_diff($names('no_contraceptive_reason_exit'), $reasons)), 'reasons with no row');
        foreach ($map['testTypes'] as $test => $name) {
            self::assertNotNull(self::$fpar->codeByName('test_type', $name), "test type $test");
        }
        foreach ([...$map['pap']['ascOrHigher'], ...$map['hivPositive']] as $name) {
            self::assertNotSame([], [...self::$fpar->codesNamedFrom('test_pap_result', $name), ...self::$fpar->codesNamedFrom('test_hiv_result', $name)], $name);
        }
    }

    public function testAgeIsTakenOnJune30AndEachPersonCountsOnceAtTheirLatestVisit(): void
    {
        $tables = self::tables([
            self::row(['patient_id' => 'A', 'birth_date' => '2008-07-01', 'visit_date' => '2026-02-01']), // 17 on June 30
            self::row(['patient_id' => 'A', 'birth_date' => '2008-07-01', 'visit_date' => '2026-09-01']), // the same person
            self::row(['patient_id' => 'B', 'birth_date' => '2008-06-30']),                              // 18 on June 30
            self::row(['patient_id' => 'C', 'birth_date' => '1960-01-01', 'sex' => 'M']),                // over 44
            self::row(['patient_id' => 'D', 'birth_date' => '2012-12-31']),                              // 13
        ]);
        $t1 = $tables->table(1);
        self::assertSame([1, 0, 1], $t1->rows[0]['cells'], 'under 15');
        self::assertSame([1, 0, 1], $t1->rows[1]['cells'], '15 to 17: A once');
        self::assertSame([1, 0, 1], $t1->rows[2]['cells'], '18 to 19');
        self::assertSame([0, 1, 1], $t1->rows[8]['cells'], 'over 44');
        self::assertSame([3, 1, 4], $t1->rows[9]['cells'], 'totals AA, BB, CC');
        self::assertSame(3, $tables->table(7)->cell('J21'), 'Table 7 counts the same women');
        self::assertSame(1, $tables->table(8)->cell('J10'));
    }

    public function testRaceAndEthnicityByTheLatestVisit(): void
    {
        $no = static fn(string $column): string => (string)self::$fpar->codeByName($column, 'No');
        $yes = static fn(string $column): string => self::$fpar->codes($column, 'urn:oid:2.16.840.1.113883.6.238')[0];
        $races = static fn(array $yesColumns): array => array_combine(
            ['race_amindian', 'race_asian', 'race_black', 'race_hpi', 'race_white'],
            array_map(fn(string $c): string => in_array($c, $yesColumns, true) ? $yes($c) : $no($c), ['race_amindian', 'race_asian', 'race_black', 'race_hpi', 'race_white'])
        );
        $hispanic = (string)self::$fpar->codeByName('ethnicity', 'Hispanic or Latino');
        $notHispanic = (string)self::$fpar->codeByName('ethnicity', 'Not Hispanic or Latino');
        $tables = self::tables([
            self::row(['patient_id' => 'A', 'ethnicity' => $hispanic, ...$races(['race_white'])]),
            self::row(['patient_id' => 'B', 'ethnicity' => $notHispanic, ...$races(['race_black', 'race_white'])]),
            self::row(['patient_id' => 'C', 'ethnicity' => '', 'race_unknown' => (string)self::$fpar->codeByName('race_unknown', 'Yes')]),
            self::row(['patient_id' => 'D', 'visit_date' => '2026-01-10', 'ethnicity' => $hispanic, ...$races(['race_asian'])]),
            self::row(['patient_id' => 'D', 'visit_date' => '2026-05-10', 'ethnicity' => $notHispanic, ...$races(['race_asian'])]),
            self::row(['patient_id' => 'E', 'sex' => 'M', 'ethnicity' => $notHispanic, ...$races(['race_amindian'])]),
        ]);
        $t2 = $tables->table(2);
        self::assertSame([1, 0, 0, 1], $t2->rows[4]['cells'], 'White, Hispanic');
        self::assertSame([0, 1, 0, 1], $t2->rows[5]['cells'], 'more than one race');
        self::assertSame([0, 0, 1, 1], $t2->rows[6]['cells'], 'unknown race and ethnicity');
        self::assertSame([0, 1, 0, 1], $t2->rows[1]['cells'], 'Asian, by the latest visit: not Hispanic');
        self::assertSame(4, $t2->cell('D8'));
        self::assertSame([0, 1, 0, 1], $tables->table(3)->rows[0]['cells'], 'the man in Table 3');
    }

    public function testIncomeByPercentOfTheGuidelinesAndNotComputedWithoutThem(): void
    {
        // 2026, the 48 contiguous states and DC: 15,960 for one person, 5,680 for each more.
        $tables = self::tables([
            self::row(['patient_id' => 'A', 'annual_household_income' => '15960', 'household_size' => '1']), // 100 percent
            self::row(['patient_id' => 'B', 'annual_household_income' => '16120', 'household_size' => '1']), // 101 percent
            self::row(['patient_id' => 'C', 'annual_household_income' => '43000', 'household_size' => '3']), // 157 percent of 27,320
            self::row(['patient_id' => 'D', 'annual_household_income' => '90000', 'household_size' => '2']), // 415 percent
            self::row(['patient_id' => 'E', 'visit_date' => '2026-02-01', 'annual_household_income' => '20000', 'household_size' => '2']),
            self::row(['patient_id' => 'E', 'visit_date' => '2026-06-01']), // no income at the latest visit: the earlier one counts
            self::row(['patient_id' => 'F']),
        ]);
        $t4 = $tables->table(4);
        self::assertSame([[2], [1], [1], [0], [1], [1], [6]], array_column($t4->rows, 'cells'), 'six people: E twice');
        self::assertStringContainsString('Federal Register, January 15, 2026', $t4->note);

        $alaska2025 = Tables::build(2025, [self::row([])], self::$fpar, self::$guidelines, 'alaska');
        self::assertNull($alaska2025->table(4)->cell('A7'), 'no 2025 figures for Alaska: not computed');
        self::assertStringContainsString('not loaded', $alaska2025->table(4)->note);
    }

    public function testInsuranceAndEnglishProficiencyRows(): void
    {
        $tables = self::tables([
            self::row(['patient_id' => 'A', 'insurance_coverage_type' => RowBuilder::COVERAGE_GOVERNMENT, 'limited_english_proficiency' => (string)self::$fpar->codeByName('limited_english_proficiency', 'Not proficient in English')]),
            self::row(['patient_id' => 'B', 'insurance_coverage_type' => RowBuilder::COVERAGE_PRIVATE, 'limited_english_proficiency' => (string)self::$fpar->codeByName('limited_english_proficiency', 'Proficient in English')]),
            self::row(['patient_id' => 'C', 'insurance_coverage_type' => RowBuilder::COVERAGE_NONE]),
            self::row(['patient_id' => 'D', 'insurance_coverage_type' => '']),
        ]);
        self::assertSame([[1], [1], [1], [1], [4]], array_column($tables->table(5)->rows, 'cells'));
        self::assertSame([[1], [1], [2], [4]], array_column($tables->table(6)->rows, 'cells'));
    }

    public function testThePrimaryMethodIsTheLatestOneAtExitAndNoMethodGoesByTheReason(): void
    {
        $method = static fn(string $name): string => (string)self::$fpar->codeByName('contraceptive_method_exit', $name);
        $reason = static fn(string $name): string => (string)self::$fpar->codeByName('no_contraceptive_reason_exit', $name);
        $none = $method('No contraceptive precautions');
        $tables = self::tables([
            self::row(['patient_id' => 'A', 'visit_date' => '2026-02-01', 'contraceptive_method_exit' => $method('Uses combined oral contraception')]),
            self::row(['patient_id' => 'A', 'visit_date' => '2026-08-01', 'contraceptive_method_exit' => $method('Uses copper intrauterine device contraception')]),
            self::row(['patient_id' => 'A', 'visit_date' => '2026-11-01']), // nothing at exit: the IUD still counts
            self::row(['patient_id' => 'B', 'contraceptive_method_exit' => $none, 'pregnancy_status' => (string)self::$fpar->codeByName('pregnancy_status', 'Pregnancy')]),
            self::row(['patient_id' => 'C', 'contraceptive_method_exit' => $none, 'no_contraceptive_reason_exit' => $reason('Abstinence')]),
            self::row(['patient_id' => 'D', 'no_contraceptive_reason_exit' => $reason('Same sex partner')]),
            self::row(['patient_id' => 'E']),
            self::row(['patient_id' => 'F', 'contraceptive_method_exit' => $method('Vasectomy')]),
            self::row(['patient_id' => 'G', 'birth_date' => '2010-01-01', 'contraceptive_method_exit' => $method('Female sterilization')]),
            self::row(['patient_id' => 'H', 'sex' => 'M', 'contraceptive_method_exit' => $method('IUD with Progestin')]),
            self::row(['patient_id' => 'I', 'sex' => 'M', 'no_contraceptive_reason_exit' => $reason('Seeking pregnancy')]),
            self::row(['patient_id' => 'J', 'sex' => 'M', 'birth_date' => '2009-03-01', 'contraceptive_method_exit' => $method('Vasectomy')]),
        ]);
        $t7 = $tables->table(7);
        $byRow = array_map(fn(array $r): int => $r['cells'][9], $t7->rows);
        self::assertSame(1, $byRow[1], 'IUD: the latest method at exit');
        self::assertSame(0, $byRow[5], 'not the pills of the earlier visit');
        self::assertSame(1, $byRow[17], 'no method, pregnant');
        self::assertSame(1, $byRow[13], 'no method, abstinence');
        self::assertSame(1, $byRow[18], 'no method, other reason');
        self::assertSame(1, $byRow[19], 'unknown');
        self::assertSame(1, $byRow[15], 'relies on vasectomy');
        self::assertSame(1, $t7->cell('B1'), 'sterilization at 16');
        self::assertSame(7, $t7->cell('J21'));
        $t8 = $tables->table(8);
        self::assertSame(1, $t8->cell('J6'), 'a man relying on his partner\'s IUD');
        self::assertSame(1, $t8->cell('J7'), 'partner seeking pregnancy');
        self::assertSame(1, $t8->cell('B1'), 'vasectomy at 17');
    }

    public function testPapChlamydiaAndTheOtherTests(): void
    {
        $pap = static fn(string $start): string => self::$fpar->codesNamedFrom('test_pap_result', $start)[0];
        $hiv = static fn(string $name): string => (string)self::$fpar->codeByName('test_hiv_result', $name);
        $papCode = self::$fpar->codes('test_pap', 'http://loinc.org')[0];
        $ctCode = self::$fpar->codes('test_chlamydia', 'http://loinc.org')[0];
        $gcCode = self::$fpar->codes('test_gonorrhea', 'http://loinc.org')[0];
        $hivCode = self::$fpar->codes('test_hiv', 'http://loinc.org')[0];
        $tables = self::tables([
            self::row(['patient_id' => 'A', 'visit_date' => '2026-02-01', 'test_pap' => $papCode, 'test_pap_result' => $pap('Negative for intraepithelial')]),
            self::row(['patient_id' => 'A', 'visit_date' => '2026-08-01', 'test_pap' => $papCode, 'test_pap_result' => $pap('Low-grade squamous')]),
            self::row(['patient_id' => 'B', 'birth_date' => '2004-01-01', 'test_pap' => $papCode, 'test_pap_result' => $pap('High-grade squamous'), 'test_chlamydia' => $ctCode, 'test_gonorrhea' => $gcCode]),
            self::row(['patient_id' => 'C', 'test_pap' => $papCode, 'test_pap_result' => $pap('Atypical glandular cells on')]),
            self::row(['patient_id' => 'D', 'sex' => 'M', 'birth_date' => '1995-01-01', 'test_chlamydia' => $ctCode, 'test_hiv' => $hivCode, 'test_hiv_result' => $hiv('Reactive')]),
            self::row(['patient_id' => 'E', 'test_hiv' => $hivCode, 'test_hiv_result' => $hiv('Non-Reactive')]),
        ]);
        self::assertSame([[3], [4], [3], [2]], array_column($tables->table(9)->rows, 'cells'), 'women tested, tests, ASC or higher, HSIL or higher (AGC counts in both, as the guide lists it)');
        $t11 = $tables->table(11);
        self::assertSame(1, $t11->cell('A4'), 'B is 20 to 24');
        self::assertSame(1, $t11->cell('B5'), 'D is 25 and over');
        self::assertSame([1, 1], $t11->rows[5]['cells']);
        $t12 = $tables->table(12);
        self::assertSame([1, 0, 1], $t12->rows[0]['cells'], 'gonorrhea');
        self::assertSame([1, 1, 2], $t12->rows[2]['cells'], 'HIV, all confidential');
        self::assertSame([null, null, 1], $t12->rows[3]['cells'], 'HIV positive: reactive, not non-reactive');
        self::assertNull($t12->cell('C5'), 'anonymous tests are entered in the portal');
    }

    /** @param list<Row> $rows */
    private static function tables(array $rows): Tables
    {
        return Tables::build(self::YEAR, $rows, self::$fpar, self::$guidelines, 'contiguous');
    }

    /** @param array<string, string> $cells */
    public static function row(array $cells): Row
    {
        $base = [...array_fill_keys(Fpar::load()->columns(), ''), 'facility_id' => '1234567893', 'patient_id' => 'TX-1', 'visit_date' => '2026-03-04',
            'birth_date' => '2000-01-15', 'sex' => 'F'];
        return new Row(1, 1, [...$base, ...$cells], [], []);
    }
}
