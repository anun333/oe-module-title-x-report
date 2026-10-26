<?php

/**
 * The year's family planning encounters, with what each FPAR 2.0 row needs, read from OpenEMR's tables.
 *
 * Which encounters count is the site's setting: the visit categories it names as Title X, plus any encounter with
 * a Title X visit record, at the facilities it names (all of them when it names none). One row per encounter.
 * A questionnaire response whose encounter form was deleted isn't read, as the Staff Worklist doesn't.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\Common\QuestionnaireStatus;
use OpenHealthcare\TitleXReport\Report\Visit;
use OpenHealthcare\TitleXReport\Report\VisitRecord;

final class EncounterSource
{
    private const CHUNK = 500;

    /**
     * patient_data columns the visit query reads that a site can lack: interpreter_needed only from 7.0.4,
     * and the Layout Editor can drop an empty stock column (#14742).
     */
    public const OPTIONAL_PATIENT_COLUMNS = ['language', 'interpreter_needed', 'ethnicity', 'race', 'monthly_income', 'family_size'];

    /**
     * @param list<int> $categories visit categories counted as Title X (openemr_postcalendar_categories.pc_catid)
     * @param list<int> $facilities facilities in the Title X project; none means all
     * @return list<Visit>
     */
    public function visits(int $year, array $categories, array $facilities): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-01-01', $year + 1);
        $records = $this->visitRecords($from, $to);
        $where = [];
        $binds = [$from, $to];
        if ($categories !== []) {
            $where[] = 'fe.pc_catid IN (' . self::marks($categories) . ')';
            array_push($binds, ...$categories);
        }
        if ($records !== []) {
            $where[] = 'fe.encounter IN (' . self::marks(array_keys($records)) . ')';
            array_push($binds, ...array_keys($records));
        }
        if ($where === []) {
            return [];
        }
        $facilityFilter = '';
        if ($facilities !== []) {
            $facilityFilter = 'AND fe.facility_id IN (' . self::marks($facilities) . ')';
            array_push($binds, ...$facilities);
        }
        $patient = self::patientSelect($this->presentColumns('patient_data', self::OPTIONAL_PATIENT_COLUMNS));
        $rows = QueryUtils::fetchRecords(
            "SELECT fe.encounter, fe.pid, fe.date, fe.facility_id, fe.pc_catid, f.facility_npi, u.npi, u.taxonomy,
                    pd.pubpid, pd.DOB, pd.sex, $patient
               FROM form_encounter AS fe
               JOIN patient_data AS pd ON pd.pid = fe.pid
               LEFT JOIN facility AS f ON f.id = fe.facility_id
               LEFT JOIN users AS u ON fe.provider_id > 0 AND u.id = fe.provider_id
              WHERE fe.date >= ? AND fe.date < ? AND (" . implode(' OR ', $where) . ") $facilityFilter
              ORDER BY fe.date, fe.encounter",
            $binds
        );
        if ($rows === []) {
            return [];
        }
        $encounters = array_map(fn(array $r): int => (int)$r['encounter'], $rows);
        $pids = array_values(array_unique(array_map(fn(array $r): int => (int)$r['pid'], $rows)));
        $cdc = $this->cdcCodes();
        $vitals = $this->vitals($encounters);
        $labs = $this->labs($encounters);
        $history = $this->byPid('SELECT pid, date, tobacco FROM history_data WHERE pid IN (%s) ORDER BY date', $pids);
        $insurance = $this->insurance($pids);
        $assessments = $this->assessments($pids);
        $smoking = $this->listEntries('smoking_status');
        $titleX = array_flip($categories);

        $visits = [];
        foreach ($rows as $r) {
            $encounter = (int)$r['encounter'];
            $pid = (int)$r['pid'];
            $date = substr((string)$r['date'], 0, 10);
            $races = [];
            foreach (explode('|', (string)($r['race'] ?? '')) as $option) {
                if (($code = $cdc['race'][$option] ?? null) !== null) {
                    $races[] = $code;
                }
            }
            $visits[] = new Visit(
                encounter: $encounter,
                pid: $pid,
                visitDate: $date,
                facilityId: (int)$r['facility_id'],
                facilityNpi: trim((string)($r['facility_npi'] ?? '')),
                providerNpi: trim((string)($r['npi'] ?? '')),
                providerTaxonomy: trim((string)($r['taxonomy'] ?? '')),
                pubpid: trim((string)($r['pubpid'] ?? '')),
                birthDate: (string)($r['DOB'] ?? ''),
                sex: (string)($r['sex'] ?? ''),
                language: (string)($r['language'] ?? ''),
                interpreterNeeded: (string)($r['interpreter_needed'] ?? ''),
                ethnicityCode: $cdc['ethnicity'][(string)($r['ethnicity'] ?? '')] ?? null,
                raceCodes: $races,
                monthlyIncome: is_numeric($r['monthly_income'] ?? null) ? (float)$r['monthly_income'] : null,
                familySize: is_numeric($r['family_size'] ?? null) ? (int)$r['family_size'] : null,
                assessment: self::inForce($assessments[$pid] ?? [], 'assessed_on', $date, fn(array $a): array => [
                    'annualIncome' => is_numeric($a['annual_income'] ?? null) ? (float)$a['annual_income'] : null,
                    'familySize' => is_numeric($a['family_size'] ?? null) ? (int)$a['family_size'] : null,
                    'declined' => ($a['method'] ?? '') === 'declined',
                ]),
                insurance: self::inForce(array_filter($insurance[$pid] ?? [], fn(array $i): bool => ($i['date_end'] ?? '') === '' || $i['date_end'] === null || substr((string)$i['date_end'], 0, 10) >= $date), 'date', $date, fn(array $i): array => ['sop' => (string)($i['cqm_sop'] ?? '')]),
                vitals: $vitals[$encounter] ?? null,
                smoking: self::smoking(self::inForce($history[$pid] ?? [], 'date', $date . ' 23:59:59', fn(array $h): array => $h), $smoking),
                visitRecord: $records[$encounter] ?? null,
                titleXCategory: isset($titleX[(int)$r['pc_catid']]),
                orderCodes: $labs['orders'][$encounter] ?? [],
                results: $labs['results'][$encounter] ?? [],
            );
        }
        return $visits;
    }

    /** @return list<array{id: int, name: string}> the visit categories, for the settings */
    public function categories(): array
    {
        return array_map(fn(array $r): array => ['id' => (int)$r['pc_catid'], 'name' => (string)$r['pc_catname']], QueryUtils::fetchRecords(
            'SELECT pc_catid, pc_catname FROM openemr_postcalendar_categories WHERE pc_cattype = 0 ORDER BY pc_catname'
        ));
    }

    /** @return list<array{id: int, name: string}> */
    public function facilities(): array
    {
        return array_map(fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], QueryUtils::fetchRecords(
            'SELECT id, name FROM facility ORDER BY name'
        ));
    }

    /**
     * The year's visit records by encounter: questionnaire responses carrying FPAR's questions, confirmed by
     * reading them (the LIKE only narrows what's read), and counted by QuestionnaireStatus.
     *
     * A response's visit is its questionnaire form's own (forms.encounter); without a form row, the visit the
     * response names when that is one of the patient's; else the patient's first visit on the day it was saved.
     * Before 8.5.0 (#14654) core stored only the leading digits of the visit's uuid, or 0, in
     * questionnaire_response.encounter, and rows saved before an upgrade keep that, so the stored number is
     * trusted only after the form's. The year is the visit's.
     *
     * @return array<int, array<string, string>>
     */
    private function visitRecords(string $from, string $to): array
    {
        [$counted, $countedBinds] = QuestionnaireStatus::condition('qr');
        $rows = QueryUtils::fetchRecords(
            "SELECT fe.encounter, qr.create_time, COALESCE(NULLIF(qr.questionnaire, ''), rep.questionnaire) AS questionnaire_json, qr.questionnaire_response AS response_json
               FROM questionnaire_response AS qr
               LEFT JOIN questionnaire_repository AS rep ON rep.id = qr.questionnaire_foreign_id
               LEFT JOIN form_questionnaire_assessments AS fqa ON fqa.response_id = qr.response_id AND fqa.pid = qr.patient_id
               LEFT JOIN forms AS fo ON fo.form_id = fqa.id AND fo.pid = fqa.pid AND fo.formdir = 'questionnaire_assessments' AND fo.deleted = 0
               LEFT JOIN form_encounter AS named ON qr.encounter > 0 AND named.encounter = qr.encounter AND named.pid = qr.patient_id
               JOIN form_encounter AS fe ON fe.pid = qr.patient_id AND fe.encounter = COALESCE(fo.encounter, named.encounter, (
                    SELECT MIN(sd.encounter) FROM form_encounter AS sd
                     WHERE sd.pid = qr.patient_id AND sd.date >= DATE(qr.create_time) AND sd.date < DATE(qr.create_time) + INTERVAL 1 DAY))
              WHERE fe.date >= ? AND fe.date < ?
                AND COALESCE(NULLIF(qr.questionnaire, ''), rep.questionnaire) LIKE ?
                AND NOT EXISTS (
                    SELECT 1 FROM form_questionnaire_assessments AS dq
                    JOIN forms AS df ON df.form_id = dq.id AND df.pid = dq.pid AND df.formdir = 'questionnaire_assessments'
                    WHERE dq.response_id = qr.response_id AND df.deleted = 1)
                AND $counted
              ORDER BY qr.create_time, qr.id",
            [$from, $to, '%86651-7%', ...$countedBinds]
        );
        $out = [];
        foreach ($rows as $r) {
            $answers = VisitRecord::answers((string)($r['questionnaire_json'] ?? ''), (string)($r['response_json'] ?? ''));
            if ($answers !== null) {
                $out[(int)$r['encounter']] = $answers; // the latest saved one wins
            }
        }
        return $out;
    }

    /** @return array{race: array<string, string>, ethnicity: array<string, string>} list option => CDC code */
    private function cdcCodes(): array
    {
        $out = ['race' => [], 'ethnicity' => []];
        foreach (QueryUtils::fetchRecords("SELECT list_id, option_id, notes FROM list_options WHERE list_id IN ('race', 'ethnicity')") as $r) {
            if (preg_match('/^\d{4}-\d$/', trim((string)$r['notes'])) === 1) {
                $out[(string)$r['list_id']][(string)$r['option_id']] = trim((string)$r['notes']);
            }
        }
        return $out;
    }

    /** @return array<string, array{title: string, code: string}> */
    private function listEntries(string $list): array
    {
        $out = [];
        foreach (QueryUtils::fetchRecords('SELECT option_id, title, codes FROM list_options WHERE list_id = ?', [$list]) as $r) {
            $out[(string)$r['option_id']] = ['title' => (string)$r['title'], 'code' => (string)($r['codes'] ?? '')];
        }
        return $out;
    }

    /**
     * The History's smoking status: its fourth field (note|type|date|status|pack-years) is a smoking_status option.
     *
     * @param array<string, mixed>|null                         $history
     * @param array<string, array{title: string, code: string}> $entries
     * @return array{title: string, code: string}|null
     */
    private static function smoking(?array $history, array $entries): ?array
    {
        $status = trim(explode('|', (string)($history['tobacco'] ?? ''))[3] ?? '');
        return $entries[$status] ?? null;
    }

    /**
     * @param list<int> $encounters
     * @return array<int, array{bps: ?int, bpd: ?int, height: ?float, weight: ?float}> the encounter's last Vitals form
     */
    private function vitals(array $encounters): array
    {
        $out = [];
        foreach (array_chunk($encounters, self::CHUNK) as $chunk) {
            $rows = QueryUtils::fetchRecords(
                "SELECT f.encounter, v.bps, v.bpd, v.height, v.weight
                   FROM forms AS f JOIN form_vitals AS v ON v.id = f.form_id
                  WHERE f.formdir = 'vitals' AND f.deleted = 0 AND f.encounter IN (" . self::marks($chunk) . ')
                  ORDER BY v.date, v.id',
                $chunk
            );
            foreach ($rows as $r) {
                $out[(int)$r['encounter']] = [
                    'bps' => is_numeric($r['bps'] ?? null) ? (int)$r['bps'] : null,
                    'bpd' => is_numeric($r['bpd'] ?? null) ? (int)$r['bpd'] : null,
                    'height' => is_numeric($r['height'] ?? null) ? (float)$r['height'] : null,
                    'weight' => is_numeric($r['weight'] ?? null) ? (float)$r['weight'] : null,
                ];
            }
        }
        return $out;
    }

    /**
     * @param list<int> $encounters
     * @return array{orders: array<int, list<string>>, results: array<int, list<array{code: string, text: string}>>}
     */
    private function labs(array $encounters): array
    {
        $out = ['orders' => [], 'results' => []];
        foreach (array_chunk($encounters, self::CHUNK) as $chunk) {
            $in = self::marks($chunk);
            foreach (QueryUtils::fetchRecords(
                "SELECT po.encounter_id, poc.procedure_code FROM procedure_order AS po
                   JOIN procedure_order_code AS poc ON poc.procedure_order_id = po.procedure_order_id
                  WHERE po.activity = 1 AND po.encounter_id IN ($in)",
                $chunk
            ) as $r) {
                $out['orders'][(int)$r['encounter_id']][] = trim((string)$r['procedure_code']);
            }
            foreach (QueryUtils::fetchRecords(
                "SELECT po.encounter_id, res.result_code, res.result, res.result_text FROM procedure_order AS po
                   JOIN procedure_report AS rep ON rep.procedure_order_id = po.procedure_order_id
                   JOIN procedure_result AS res ON res.procedure_report_id = rep.procedure_report_id
                  WHERE po.activity = 1 AND po.encounter_id IN ($in)",
                $chunk
            ) as $r) {
                $out['results'][(int)$r['encounter_id']][] = ['code' => trim((string)$r['result_code']),
                    'text' => trim((string)($r['result'] ?? '')) !== '' ? (string)$r['result'] : (string)($r['result_text'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * @param list<int> $pids
     * @return array<int, list<array<string, mixed>>>
     */
    private function insurance(array $pids): array
    {
        $end = $this->hasColumn('insurance_data', 'date_end') ? 'i.date_end' : 'NULL AS date_end';
        return $this->byPid(
            "SELECT i.pid, i.date, $end, ic.cqm_sop FROM insurance_data AS i
               LEFT JOIN insurance_companies AS ic ON ic.id = i.provider
              WHERE i.type = 'primary' AND i.provider IS NOT NULL AND i.provider <> '' AND i.provider <> '0' AND i.pid IN (%s)
              ORDER BY i.date",
            $pids
        );
    }

    /**
     * Sliding Fee's assessments, when that module is installed: a read of another openhealthcare module's table,
     * accepted on the condition that it is off without the table (the column check below), so Title X works alone.
     *
     * @param list<int> $pids
     * @return array<int, list<array<string, mixed>>>
     */
    private function assessments(array $pids): array
    {
        if (!$this->hasColumn('sliding_fee_assessment', 'assessed_on')) {
            return [];
        }
        return $this->byPid('SELECT pid, assessed_on, method, annual_income, family_size FROM sliding_fee_assessment WHERE pid IN (%s) ORDER BY assessed_on, id', $pids);
    }

    /**
     * @param list<int> $pids
     * @return array<int, list<array<string, mixed>>>
     */
    private function byPid(string $sql, array $pids): array
    {
        $out = [];
        foreach (array_chunk($pids, self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords(sprintf($sql, self::marks($chunk)), $chunk) as $r) {
                $out[(int)$r['pid']][] = $r;
            }
        }
        return $out;
    }

    /**
     * The last row dated on or before the day, shaped by $shape; rows come in date order.
     *
     * @template T
     * @param list<array<string, mixed>>        $rows
     * @param callable(array<string, mixed>): T $shape
     * @return T|null
     */
    private static function inForce(array $rows, string $field, string $day, callable $shape): mixed
    {
        $found = null;
        foreach ($rows as $row) {
            $when = (string)($row[$field] ?? '');
            if ($when !== '' && substr($when, 0, strlen($day)) <= $day) {
                $found = $row;
            }
        }
        return $found === null ? null : $shape($found);
    }

    /**
     * The optional patient columns as the visit query selects them: the column where the site has it, else NULL
     * under its name, so the rest of the report reads a missing column as an empty answer.
     *
     * @param list<string> $present
     */
    public static function patientSelect(array $present): string
    {
        return implode(', ', array_map(
            fn(string $column): string => in_array($column, $present, true) ? "pd.$column" : "NULL AS $column",
            self::OPTIONAL_PATIENT_COLUMNS
        ));
    }

    /**
     * Which of these columns the table has, in one lookup.
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private function presentColumns(string $table, array $columns): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')',
            [$table, ...$columns]
        );
        $present = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? $row['NAME'] ?? $row['COLUMN_NAME'] ?? null;
            if (is_string($name) && in_array($name, $columns, true)) {
                $present[] = $name;
            }
        }
        return $present;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return QueryUtils::fetchRecords(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        ) !== [];
    }

    /** @param list<int> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
