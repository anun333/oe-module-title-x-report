<?php

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Tests;

use OpenHealthcare\TitleXReport\Reference\Fpar;
use OpenHealthcare\TitleXReport\Report\CsvFile;
use OpenHealthcare\TitleXReport\Report\DataQuality;
use OpenHealthcare\TitleXReport\Report\LabTests;
use OpenHealthcare\TitleXReport\Report\Problems;
use OpenHealthcare\TitleXReport\Report\RowBuilder;
use OpenHealthcare\TitleXReport\Report\Visit;
use OpenHealthcare\TitleXReport\Report\VisitRecord;
use PHPUnit\Framework\TestCase;

/**
 * The report's rules, against OPA's own reference file (data/fpar2.json) and Clinic Forms' real visit record
 * export (tests/fixtures). Every patient here is made up.
 */
final class ReportTest extends TestCase
{
    private static Fpar $fpar;

    public static function setUpBeforeClass(): void
    {
        self::$fpar = Fpar::load();
    }

    public function testTheReferenceFileIsOpasAndShipsNoUsExtensionCode(): void
    {
        $data = json_decode((string)file_get_contents(dirname(__DIR__) . '/data/fpar2.json'), true);
        self::assertSame('4/3/2026', self::$fpar->version);
        self::assertCount(48, self::$fpar->columns());
        self::assertSame(['facility_id', 'provider_id', 'provider_role', 'patient_id', 'visit_date'], array_slice(self::$fpar->columns(), 0, 5));
        $snomed = 0;
        foreach ($data['elements'] as $element) {
            foreach ($element['values'] as $v) {
                if ($v['system'] === 'http://snomed.info/sct') {
                    $snomed++;
                    self::assertDoesNotMatchRegularExpression('/^\d+1000124\d{3}$/', $v['code'], "{$element['column']} ships a US Extension code");
                }
            }
        }
        self::assertGreaterThan(100, $snomed, 'the check saw SNOMED codes');
        self::assertSame(25, array_sum($data['left_out']), 'the US Extension codes in OPA file are counted as left out, by column');
        self::assertDoesNotMatchRegularExpression('/\d+1000124\d{3}/', (string)file_get_contents(dirname(__DIR__) . '/data/fpar2.json'), 'not even listed');
    }

    public function testEveryVisitRecordAnswerIsOneOpaAccepts(): void
    {
        $q = json_decode((string)file_get_contents(__DIR__ . '/fixtures/title-x-visit-record.json'), true);
        $checked = 0;
        $walk = function (array $items) use (&$walk, &$checked): void {
            foreach ($items as $item) {
                foreach ($item['code'] ?? [] as $c) {
                    $column = self::$fpar->columnForQuestion($c['code']);
                    foreach ($column === null ? [] : $item['answerOption'] ?? [] as $o) {
                        self::assertTrue(self::$fpar->valid($column, $o['valueCoding']['code']), "$column {$o['valueCoding']['code']}");
                        $checked++;
                    }
                }
                $walk($item['item'] ?? []);
            }
        };
        $walk($q['item']);
        self::assertSame(78, $checked);
    }

    public function testAVisitRecordIsReadByItsQuestionCodesWhereverAnswersNest(): void
    {
        $questionnaire = (string)file_get_contents(__DIR__ . '/fixtures/title-x-visit-record.json');
        $response = json_encode(['resourceType' => 'QuestionnaireResponse', 'item' => [
            ['linkId' => 'b7', 'item' => [
                ['linkId' => '/82810-3', 'answer' => [['valueCoding' => ['system' => 'http://snomed.info/sct', 'code' => '60001007']]]],
                ['linkId' => '/86651-7', 'answer' => [['valueCoding' => ['code' => '22523008'], 'item' => [
                    ['linkId' => '/86652-5', 'answer' => [['valueCoding' => ['code' => '16076005']]]],
                ]]]],
            ]],
        ]]);
        self::assertSame(['82810-3' => '60001007', '86651-7' => '22523008', '86652-5' => '16076005'], VisitRecord::answers($questionnaire, (string)$response));
        $phq = json_encode(['item' => [['linkId' => 'a', 'code' => [['system' => 'http://loinc.org', 'code' => '82810-3']]]]]);
        self::assertNull(VisitRecord::answers((string)$phq, (string)$response), 'one FPAR question does not make a visit record');
    }

    public function testLabsArePerformedByOpasTestCodesAndResultsReadAsOpasQualifiers(): void
    {
        $labs = new LabTests(self::$fpar);
        $chlamydia = self::$fpar->codes('test_chlamydia', 'http://loinc.org')[0];
        $gonorrhea = self::$fpar->codes('test_gonorrhea', 'http://loinc.org')[0];
        $cells = $labs->columns(['LOCAL-PANEL-1'], [['code' => $chlamydia, 'text' => 'Not Detected'], ['code' => $gonorrhea, 'text' => 'NEGATIVE']]);
        self::assertSame($chlamydia, $cells['test_chlamydia'], 'a panel under a local code counts by its result codes');
        self::assertSame(self::$fpar->codeByName('test_chlamydia_result', 'Not detected'), $cells['test_chlamydia_result']);
        self::assertSame(self::$fpar->codeByName('test_gonorrhea_result', 'Negative'), $cells['test_gonorrhea_result']);
        self::assertSame('', $cells['test_hiv'], 'nothing found is blank, not "not performed"');
        self::assertSame(self::$fpar->codeByName('test_hiv_result', 'Non-Reactive'), $labs->result('hiv', 'Non-reactive'));
        self::assertSame(self::$fpar->codeByName('test_hiv_result', 'Reactive'), $labs->result('hiv', 'reactive'));
        self::assertSame(self::$fpar->codesNamedFrom('test_pap_result', 'Negative for intraepithelial')[0], $labs->result('pap', 'NILM'));
        self::assertSame(self::$fpar->codesNamedFrom('test_pap_result', 'Atypical squamous cells, cannot exclude')[0], $labs->result('pap', 'ASC-H'));
        self::assertSame('', $labs->result('hpv', 'see note'));
    }

    public function testAFullVisitBecomesOpasRow(): void
    {
        $answers = ['98076-3' => 'LA33-6', '86645-9' => 'LA26440-0', '86649-1' => '22523008', '82810-3' => '60001007',
            '86654-1' => '373066001', '86655-8' => '373067005', '86651-7' => '22523008', '86652-5' => '16076005'];
        $row = (new RowBuilder(self::$fpar))->build($this->visit(['visitRecord' => $answers]));
        $c = $row->cells;
        self::assertFalse($row->refused());
        self::assertSame([], $row->toFix);
        self::assertSame(['1234567893', '1922334455', self::$fpar->codeByName('provider_role', 'Nurse practitioner'), 'TX-1001', '2026-03-04', '1998-07-01', 'F'],
            [$c['facility_id'], $c['provider_id'], $c['provider_role'], $c['patient_id'], $c['visit_date'], $c['birth_date'], $c['sex']]);
        self::assertSame(self::$fpar->codeByName('limited_english_proficiency', 'Not proficient in English'), $c['limited_english_proficiency'], 'Spanish');
        self::assertSame('2135-2', $c['ethnicity'], 'a Hispanic origin code rolls up to Hispanic or Latino');
        self::assertSame(['2106-3', self::$fpar->codeByName('race_asian', 'No')], [$c['race_white'], $c['race_asian']]);
        self::assertSame(self::$fpar->codeByName('race_unknown', 'No'), $c['race_unknown']);
        self::assertSame(['18500', '3'], [$c['annual_household_income'], $c['household_size']], 'the Sliding Fee assessment, not the demographics');
        self::assertSame(['3', '2'], [$c['insurance_coverage_type'], $c['visit_payer']], 'Medicaid: government coverage, payer 2');
        self::assertSame(['118', '76', '64.0', 'in_i', '142.5', 'lb_av'], [$c['blood_pressure_systolic'], $c['blood_pressure_diastolic'], $c['height'], $c['height_unit'], $c['weight'], $c['weight_unit']]);
        self::assertSame(self::$fpar->codeByName('tobacco_smoking_status', 'Current some day smoker'), $c['tobacco_smoking_status'], 'a status whose code OPA lacks goes by its name');
        self::assertSame(['60001007', 'LA26440-0', '22523008', '16076005', 'LA33-6'], [$c['pregnancy_status'], $c['pregnancy_intention'], $c['contraceptive_method_exit'], $c['contraceptive_method_provided'], $c['want_talk_contraception']]);
        foreach (self::$fpar->columns() as $column) {
            self::assertTrue($c[$column] === '' || self::$fpar->valid($column, $c[$column]) || in_array($column, ['facility_id', 'provider_id', 'patient_id', 'visit_date', 'birth_date', 'annual_household_income', 'household_size', 'blood_pressure_systolic', 'blood_pressure_diastolic', 'height', 'weight'], true), "$column {$c[$column]} is OPA's");
        }
    }

    public function testARowMissingAKeyIsRefusedAndProblemsAreListed(): void
    {
        $builder = new RowBuilder(self::$fpar);
        $refused = $builder->build($this->visit(['birthDate' => '0000-00-00', 'sex' => 'UNK']));
        self::assertSame(['birth_date', 'sex'], $refused->missingKeys);
        $noRecord = $builder->build($this->visit(['visitRecord' => null, 'titleXCategory' => true]));
        self::assertSame([Problems::NO_VISIT_RECORD], array_column($noRecord->toFix, 'key'));
        $odd = $builder->build($this->visit(['visitRecord' => ['86651-7' => '22523008', '86653-3' => '74964007', '82810-3' => 'pregnant-ish']]));
        self::assertSame([Problems::INVALID_ANSWER, Problems::METHOD_NOT_PROVIDED, Problems::METHOD_AND_REASON_EXIT], array_column($odd->toFix, 'key'));
        self::assertSame('', $odd->cells['pregnancy_status']);
        self::assertStringContainsString('pregnant-ish', Problems::text($odd->toFix[0], fn(string $s): string => $s));
        $noSop = $builder->build($this->visit(['insurance' => ['sop' => '']]));
        self::assertSame([Problems::NO_PAYER_CODE], array_column($noSop->toFix, 'key'));
        $none = $builder->build($this->visit(['insurance' => null]));
        self::assertSame(['8', '81'], [$none->cells['insurance_coverage_type'], $none->cells['visit_payer']], 'no coverage on the visit date: self-pay');
    }

    public function testUnknownsStayBlankRatherThanGuessed(): void
    {
        $c = (new RowBuilder(self::$fpar))->build($this->visit(['language' => '', 'interpreterNeeded' => '', 'raceCodes' => ['2131-1'],
            'ethnicityCode' => null, 'assessment' => ['annualIncome' => null, 'familySize' => null, 'declined' => true], 'providerTaxonomy' => '', 'smoking' => null]))->cells;
        self::assertSame(['', '', '', ''], [$c['limited_english_proficiency'], $c['ethnicity'], $c['annual_household_income'], $c['provider_role']]);
        self::assertSame(['', self::$fpar->codeByName('race_unknown', 'Yes')], [$c['race_white'], $c['race_unknown']], 'Other Race is race not known');
        $english = (new RowBuilder(self::$fpar))->build($this->visit(['language' => 'English', 'interpreterNeeded' => 'no', 'providerTaxonomy' => '390200000X']))->cells;
        self::assertSame(self::$fpar->codeByName('limited_english_proficiency', 'Proficient in English'), $english['limited_english_proficiency']);
        self::assertSame(self::$fpar->codeByName('provider_role', 'Other'), $english['provider_role'], 'a taxonomy in no rule is Other');
    }

    public function testTheCsvHasOpasHeaderAndLeavesRefusedRowsOut(): void
    {
        $builder = new RowBuilder(self::$fpar);
        $csv = CsvFile::write(self::$fpar->columns(), [$builder->build($this->visit([])), $builder->build($this->visit(['sex' => '']))]);
        $lines = array_values(array_filter(explode("\n", $csv)));
        self::assertCount(2, $lines, 'header and the one kept row');
        self::assertSame(implode(',', self::$fpar->columns()), $lines[0]);
        self::assertStringContainsString(',2026-03-04,1998-07-01,F,', $lines[1]);
    }

    public function testDataQualityCountsUsersAtTheirLatestVisit(): void
    {
        $builder = new RowBuilder(self::$fpar);
        $rows = [
            $builder->build($this->visit(['pubpid' => 'A', 'visitDate' => '2026-01-02', 'assessment' => null, 'monthlyIncome' => null])),
            $builder->build($this->visit(['pubpid' => 'A', 'visitDate' => '2026-05-02'])),
            $builder->build($this->visit(['pubpid' => 'B', 'assessment' => null, 'monthlyIncome' => null])),
        ];
        $warnings = DataQuality::warnings($rows, (string)self::$fpar->codeByName('race_unknown', 'Yes'));
        $income = array_values(array_filter($warnings, fn(array $w): bool => $w['label'] === 'income unknown'));
        self::assertSame([1, 2], [$income[0]['unknown'], $income[0]['users']], 'A at the later visit has income; B does not');
    }

    /** @param array<string, mixed> $with */
    private function visit(array $with): Visit
    {
        $v = [
            'encounter' => 501, 'pid' => 7, 'visitDate' => '2026-03-04 10:15:00', 'facilityId' => 3, 'facilityNpi' => '1234567893',
            'providerNpi' => '1922334455', 'providerTaxonomy' => '363LF0000X', 'pubpid' => 'TX-1001', 'birthDate' => '1998-07-01',
            'sex' => 'Female', 'language' => 'spanish', 'interpreterNeeded' => '', 'ethnicityCode' => '2148-5', 'raceCodes' => ['2106-3'],
            'monthlyIncome' => 900.0, 'familySize' => 2, 'assessment' => ['annualIncome' => 18500.0, 'familySize' => 3, 'declined' => false],
            'insurance' => ['sop' => '2'], 'vitals' => ['bps' => 118, 'bpd' => 76, 'height' => 64.0, 'weight' => 142.5],
            // A code that isn't one of OPA's current values (OpenEMR's own is a US Extension code), so the status goes by its name.
            'smoking' => ['title' => 'Current some day smoker', 'code' => 'SNOMED-CT:999999999'],
            'visitRecord' => [], 'titleXCategory' => true, 'orderCodes' => [], 'results' => [],
        ];
        return new Visit(...[...$v, ...$with]);
    }
}
