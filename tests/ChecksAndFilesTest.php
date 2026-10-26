<?php

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Tests;

use OpenHealthcare\TitleXReport\Reference\Fpar;
use OpenHealthcare\TitleXReport\Reference\PovertyGuidelines;
use OpenHealthcare\TitleXReport\Report\CsvFile;
use OpenHealthcare\TitleXReport\Report\LabFile;
use OpenHealthcare\TitleXReport\Report\PriorYear;
use OpenHealthcare\TitleXReport\Report\Row;
use OpenHealthcare\TitleXReport\Report\TableChecks;
use OpenHealthcare\TitleXReport\Report\Tables;
use PHPUnit\Framework\TestCase;

/**
 * OPA's checks on the tables (FPAR 2.0 Data Quality Checks Overview, December 2025), the previous year check,
 * and the two files a site uploads instead of the combined one (FPAR 2.0 Sample File, April 2026).
 */
final class ChecksAndFilesTest extends TestCase
{
    private static Fpar $fpar;
    private static PovertyGuidelines $guidelines;

    public static function setUpBeforeClass(): void
    {
        self::$fpar = Fpar::load();
        self::$guidelines = PovertyGuidelines::load();
    }

    public function testTheAgeChecksFlagSterilizationAndVasectomyUnderOpasAges(): void
    {
        $method = static fn(string $name): string => (string)self::$fpar->codeByName('contraceptive_method_exit', $name);
        $checks = self::checks([
            TablesTest::row(['patient_id' => 'A', 'birth_date' => '2007-01-01', 'contraceptive_method_exit' => $method('Female sterilization')]), // 19
            TablesTest::row(['patient_id' => 'B', 'birth_date' => '2006-06-30', 'contraceptive_method_exit' => $method('Female sterilization')]), // 20: fine
            TablesTest::row(['patient_id' => 'C', 'birth_date' => '2009-01-01', 'contraceptive_method_exit' => $method('Vasectomy')]),           // 17, a woman relying on vasectomy
            TablesTest::row(['patient_id' => 'D', 'birth_date' => '2008-01-01', 'contraceptive_method_exit' => $method('Vasectomy')]),           // 18: fine for a woman
            TablesTest::row(['patient_id' => 'E', 'sex' => 'M', 'birth_date' => '2007-03-01', 'contraceptive_method_exit' => $method('Vasectomy')]), // 19, a man
        ]);
        $byKey = [];
        foreach ($checks as $c) {
            $byKey[$c['key']] = $c;
        }
        self::assertSame('T7_C1', $byKey[TableChecks::STERILIZED_UNDER_20]['cells']);
        self::assertSame('T7_B16', $byKey[TableChecks::VASECTOMY_UNDER_18]['cells']);
        self::assertSame('T8_C1', $byKey[TableChecks::VASECTOMY_UNDER_20]['cells']);
        self::assertSame('Female sterilization as the primary method for 1 female users under 20 (T7_C1)', TableChecks::text($byKey[TableChecks::STERILIZED_UNDER_20], fn(string $s): string => $s));

        $fine = self::checks([TablesTest::row(['patient_id' => 'B', 'birth_date' => '2006-06-30', 'contraceptive_method_exit' => $method('Female sterilization')])]);
        self::assertSame([], array_values(array_filter($fine, fn(array $c): bool => in_array($c['key'], [TableChecks::STERILIZED_UNDER_20, TableChecks::VASECTOMY_UNDER_18, TableChecks::VASECTOMY_UNDER_20], true))), 'sterilized at 20');
    }

    public function testTheShareChecksUseOpasThresholds(): void
    {
        $method = static fn(string $name): string => (string)self::$fpar->codeByName('contraceptive_method_exit', $name);
        $rows = [];
        for ($i = 0; $i < 19; $i++) {
            $rows[] = TablesTest::row(['patient_id' => "P$i", 'contraceptive_method_exit' => $method('Uses combined oral contraception'),
                'insurance_coverage_type' => '3', 'annual_household_income' => '20000', 'household_size' => '2']);
        }
        $rows[] = TablesTest::row(['patient_id' => 'U', 'insurance_coverage_type' => '3', 'annual_household_income' => '20000', 'household_size' => '2']); // method unknown: 1 of 20 is 5 percent
        $checks = self::checks($rows);
        $texts = array_map(fn(array $c): string => TableChecks::text($c, fn(string $s): string => $s), $checks);
        self::assertContains('method unknown or not reported: 1 of 20 (5 percent; OPA flags 5 percent or more)', $texts);
        self::assertContains('Method unknown or not reported, ages 25 to 29: 1 of 20 (5 percent; OPA flags 5 percent or more)', $texts);
        self::assertContains('Every user is in one insurance category: Public health insurance covering primary medical care', $texts);
        self::assertNotContains('income unknown or not reported', array_map(fn(array $c): string => (string)($c['args'][0] ?? ''), $checks), 'everyone has income');
    }

    public function testThePreviousYearCheckFlagsHalfOrMoreAndFromNone(): void
    {
        $build = static fn(int $women, int $men): Tables => Tables::build(2026, [
            ...array_map(fn(int $i): Row => TablesTest::row(['patient_id' => "F$i"]), $women > 0 ? range(1, $women) : []),
            ...array_map(fn(int $i): Row => TablesTest::row(['patient_id' => "M$i", 'sex' => 'M']), $men > 0 ? range(1, $men) : []),
        ], self::$fpar, self::$guidelines, 'contiguous');
        $changes = PriorYear::changes($build(15, 2), $build(10, 0));
        $cells = array_column($changes, 'change', 'cell');
        self::assertSame(50, $cells['T1_A5'], '10 to 15 women aged 25 to 29 is a 50 percent change');
        self::assertArrayHasKey('T1_B5', $cells, 'men from none');
        self::assertNull($cells['T1_B5']);
        self::assertNotContains('T1_A5', array_column(PriorYear::changes($build(14, 0), $build(10, 0)), 'cell'), '40 percent is under the threshold');
        self::assertSame(-100, array_column(PriorYear::changes($build(0, 2), $build(10, 2)), 'change', 'cell')['T1_A5'], 'down to none');
    }

    public function testTheTwoFilesSplitTheCombinedOneTheWayOpasSampleDoes(): void
    {
        $fpar = self::$fpar;
        self::assertSame(['facility_id', 'patient_id', 'visit_date', 'birth_date', 'test_type', 'test_result'], $fpar->layout('lab'));
        self::assertSame(array_values(array_filter($fpar->columns(), fn(string $c): bool => !str_ends_with($c, '_result'))), $fpar->layout('encounter'), 'the encounter file is the combined one without the six results');

        $ct = $fpar->codes('test_chlamydia', 'http://loinc.org')[0];
        $notDetected = (string)$fpar->codeByName('test_chlamydia_result', 'Not detected');
        $rows = [
            TablesTest::row(['patient_id' => 'A', 'test_chlamydia' => $ct, 'test_chlamydia_result' => $notDetected, 'test_hiv' => $fpar->codes('test_hiv', 'http://loinc.org')[0]]),
            TablesTest::row(['patient_id' => 'B']),
            new Row(9, 9, [...TablesTest::row([])->cells, 'patient_id' => 'R', 'test_chlamydia_result' => $notDetected], ['sex'], []),
        ];
        $records = LabFile::records($rows, $fpar, Tables::loadMap()['testTypes']);
        self::assertSame([['facility_id' => '1234567893', 'patient_id' => 'A', 'visit_date' => '2026-03-04', 'birth_date' => '2000-01-15', 'test_type' => 'CHL', 'test_result' => $notDetected]], $records, 'one row per result: the HIV test without one has none, and the refused row is left out');
        $lines = explode("\n", trim(CsvFile::records($fpar->layout('lab'), $records)));
        self::assertSame(['facility_id,patient_id,visit_date,birth_date,test_type,test_result', "1234567893,A,2026-03-04,2000-01-15,CHL,$notDetected"], $lines);
        $encounter = explode("\n", trim(CsvFile::write($fpar->layout('encounter'), $rows)));
        self::assertCount(3, $encounter, 'header and the two written visits');
        self::assertStringNotContainsString('_result', $encounter[0]);
    }

    /**
     * @param list<Row> $rows
     * @return list<array{key: string, table: int, cells: string, args: list<string|int>}>
     */
    private static function checks(array $rows): array
    {
        return TableChecks::run(Tables::build(2026, $rows, self::$fpar, self::$guidelines, 'contiguous'));
    }
}
