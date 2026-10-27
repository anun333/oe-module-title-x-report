<?php

/**
 * "Confidential visits" in the Staff Worklist: visits in the categories the site uses for confidential services
 * (Title X Report's settings) where the client's confidentiality is at risk through billing. 42 CFR 59.10(a) asks that
 * "Reasonable efforts to collect charges without jeopardizing client confidentiality must be made", and 59.10(b) that no
 * staff "notify a parent or guardian before or after a minor has requested and/or received Title X family planning
 * services". Two rows per visit at most:
 *
 *  - a claim queued or sent to an insurer: the policyholder may see it on an explanation of benefits. The row asks staff
 *    to check the client was told and agreed; it never names a payer.
 *  - a balance the patient owes while a statement would go to someone else: since OpenEMR 8.5.0 core addresses
 *    statements to the guardian on Demographics (StatementRecipient); before then they're addressed to the patient,
 *    and a guardian on file means one may still see them. The row asks staff to hold the statement or bill the client.
 *
 * Built on openhealthcare-common's Worklist contract (1.5.0). The visit's sensitivity is passed on, as every tab does, so
 * the Worklist shows the row only to users core allows to see the visit; it doesn't decide what is confidential.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Worklist;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\Common\Contract\Worklist\ItemKey;
use OpenHealthcare\Common\Contract\Worklist\PatientRef;
use OpenHealthcare\Common\Contract\Worklist\WorkItem;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;
use OpenHealthcare\TitleXReport\Repository\Settings;

final class ConfidentialVisitsSource implements WorkSource
{
    public const SOURCE = 'titlexconf';
    /** How far back the tab looks: a year of visits. */
    public const LOOKBACK_DAYS = 365;
    /** The two kinds of row, as the last digit of the item's reference (the visit number times ten, plus the kind). */
    public const CLAIM = 1;
    public const BALANCE = 2;
    /** Core's statement addressee, from OpenEMR 8.5.0 (#14794). */
    public const STATEMENT_RECIPIENT = 'OpenEMR\\Billing\\Statement\\StatementRecipient';

    /**
     * @param ?\Closure(array<string, mixed>): list<string> $recipient the statement's "to" block for a patient row;
     *        core's StatementRecipient::lines() where it exists (tests pass their own)
     */
    public function __construct(
        private readonly \DateTimeImmutable $now,
        private readonly Settings $settings = new Settings(),
        private readonly ?\Closure $recipient = null,
    ) {
    }

    public function id(): string
    {
        return self::SOURCE;
    }

    public function collect(?int $pid = null): array
    {
        $categories = $this->settings->confidential();
        if ($categories === []) {
            return [];
        }
        $facilities = $this->settings->facilities();
        $binds = [...$categories, $this->now->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d')];
        $where = '';
        if ($facilities !== []) {
            $where .= ' AND fe.facility_id IN (' . self::marks($facilities) . ')';
            array_push($binds, ...$facilities);
        }
        if ($pid !== null) {
            $where .= ' AND fe.pid = ?';
            $binds[] = $pid;
        }
        $visits = QueryUtils::fetchRecords(
            "SELECT fe.pid, fe.encounter, fe.date, fe.facility_id, COALESCE(fe.sensitivity, '') AS sensitivity, COALESCE(c.pc_catname, '') AS category
               FROM form_encounter AS fe
               LEFT JOIN openemr_postcalendar_categories AS c ON c.pc_catid = fe.pc_catid
              WHERE fe.pc_catid IN (" . self::marks($categories) . ") AND fe.date >= ?$where
              ORDER BY fe.date",
            $binds
        );
        if ($visits === []) {
            return [];
        }
        $byEncounter = [];
        foreach ($visits as $v) {
            $byEncounter[(int)$v['encounter']] = $v;
        }
        $claims = $this->claims(array_keys($byEncounter));
        $balances = $this->balances(array_keys($byEncounter));
        $pids = array_values(array_unique(array_map(fn(array $v): int => (int)$v['pid'], $visits)));
        $patients = $this->patients($pids);

        $items = [];
        foreach ($byEncounter as $encounter => $v) {
            $p = (int)$v['pid'];
            if (!isset($patients[$p])) {
                continue;
            }
            $patient = PatientRef::fromRow($p, $patients[$p], $this->now);
            $date = substr((string)$v['date'], 0, 10);
            $common = [
                'patient' => $patient,
                'since' => new \DateTimeImmutable($date),
                'ownerId' => null,
                'actionLabel' => xl('Open the visit'),
                'actionUrl' => '/interface/patient_file/encounter/encounter_top.php?set_encounter=' . $encounter,
                'actionMode' => 'chart',
                'dismissible' => true,
                'siteId' => (int)$v['facility_id'] > 0 ? (int)$v['facility_id'] : null,
                'sensitivity' => (string)$v['sensitivity'] === '' ? null : (string)$v['sensitivity'],
            ];
            $visit = sprintf(xl('Visit of %s'), $date) . ((string)$v['category'] === '' ? '' : ' · ' . (string)$v['category']);

            $claim = $claims[$encounter] ?? null;
            if ($claim !== null) {
                $queued = $claim['status'] === 1;
                $items[] = new WorkItem(
                    ...$common,
                    key: new ItemKey(self::SOURCE, $p, (string)($encounter * 10 + self::CLAIM)),
                    title: $queued ? xl('Confidential visit queued to bill insurance') : xl('Confidential visit billed to insurance'),
                    detail: implode(' · ', [
                        $visit,
                        sprintf(xl('Insurance level %d'), $claim['level']),
                        xl('Check the client was told the policyholder may see it and agreed to bill insurance (42 CFR 59.10(a))'),
                    ]),
                    priority: $queued ? WorkItem::PRIORITY_HIGH : WorkItem::PRIORITY_NORMAL,
                );
            }

            $balance = $balances[$encounter] ?? 0.0;
            $to = $balance > 0.005 ? $this->addressee($patients[$p]) : null;
            if ($to !== null) {
                $items[] = new WorkItem(
                    ...$common,
                    key: new ItemKey(self::SOURCE, $p, (string)($encounter * 10 + self::BALANCE)),
                    title: $to === 'guardian' ? xl('Confidential visit with a balance: statements go to the guardian')
                        : xl('Confidential visit with a balance: a guardian is on file'),
                    detail: implode(' · ', [
                        $visit,
                        sprintf(xl('Balance %s'), number_format($balance, 2)),
                        xl('Hold the statement for this visit or bill the client directly (42 CFR 59.10)'),
                    ]),
                    priority: WorkItem::PRIORITY_HIGH,
                );
            }
        }
        return $items;
    }

    /**
     * Who a statement would reach other than the patient: "guardian" when core addresses statements to the guardian
     * (8.5.0 and later), "on_file" when an older core addresses them to the patient but a guardian is on Demographics,
     * or null when it would go to the patient alone.
     *
     * @param array<string, mixed> $row a patient_data row with names, address and guardian columns
     */
    public function addressee(array $row): ?string
    {
        $recipient = $this->recipient;
        if ($recipient === null && class_exists(self::STATEMENT_RECIPIENT)) {
            $class = self::STATEMENT_RECIPIENT;
            $recipient = static fn(array $r): array => $class::lines($r);
        }
        $guardian = trim((string)($row['guardiansname'] ?? ''));
        if ($recipient !== null) {
            $name = trim((string)(($recipient)($row)[0] ?? ''));
            return $guardian !== '' && $name === $guardian ? 'guardian' : null;
        }
        return $guardian !== '' ? 'on_file' : null;
    }

    /**
     * The latest claim to an insurer (payer level 1 to 3) per visit: its status (1 queued, 2 or more billed) and level.
     *
     * @param list<int> $encounters
     * @return array<int, array{status: int, level: int}>
     */
    private function claims(array $encounters): array
    {
        $out = [];
        foreach (array_chunk($encounters, 500) as $chunk) {
            $rows = QueryUtils::fetchRecords(
                'SELECT c.encounter_id AS encounter, c.version, c.status, c.payer_type
                   FROM claims AS c
                  WHERE c.payer_type > 0 AND c.status > 0 AND c.encounter_id IN (' . self::marks($chunk) . ')
                  ORDER BY c.encounter_id, c.version',
                $chunk
            );
            foreach ($rows as $r) {
                $out[(int)$r['encounter']] = ['status' => (int)$r['status'], 'level' => (int)$r['payer_type']]; // the last version wins
            }
        }
        return $out;
    }

    /**
     * What the patient side owes per visit: the charges on the fee sheet and products sold, less payments and adjustments
     * not voided.
     *
     * @param list<int> $encounters
     * @return array<int, float>
     */
    private function balances(array $encounters): array
    {
        $out = [];
        foreach (array_chunk($encounters, 500) as $chunk) {
            $marks = self::marks($chunk);
            foreach (QueryUtils::fetchRecords("SELECT encounter, SUM(fee) AS amount FROM billing WHERE activity = 1 AND encounter IN ($marks) GROUP BY encounter", $chunk) as $r) {
                $out[(int)$r['encounter']] = ($out[(int)$r['encounter']] ?? 0.0) + (float)$r['amount'];
            }
            foreach (QueryUtils::fetchRecords("SELECT encounter, SUM(fee) AS amount FROM drug_sales WHERE encounter IN ($marks) GROUP BY encounter", $chunk) as $r) {
                $out[(int)$r['encounter']] = ($out[(int)$r['encounter']] ?? 0.0) + (float)$r['amount'];
            }
            foreach (QueryUtils::fetchRecords("SELECT encounter, SUM(pay_amount) + SUM(adj_amount) AS amount FROM ar_activity WHERE deleted IS NULL AND encounter IN ($marks) GROUP BY encounter", $chunk) as $r) {
                $out[(int)$r['encounter']] = ($out[(int)$r['encounter']] ?? 0.0) - (float)$r['amount'];
            }
        }
        return $out;
    }

    /**
     * @param list<int> $pids
     * @return array<int, array<string, mixed>>
     */
    private function patients(array $pids): array
    {
        $out = [];
        foreach (array_chunk($pids, 500) as $chunk) {
            foreach (QueryUtils::fetchRecords('SELECT pd.pid, ' . PatientRef::SQL_COLUMNS . ', pd.street, pd.street_line_2, pd.city, pd.state, pd.postal_code,
                    pd.guardiansname, pd.guardianaddress, pd.guardiancity, pd.guardianstate, pd.guardianpostalcode
                    FROM patient_data AS pd WHERE pd.pid IN (' . self::marks($chunk) . ')', $chunk) as $row) {
                $out[(int)$row['pid']] = $row;
            }
        }
        return $out;
    }

    /** @param list<int> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
