<?php

/**
 * OPA's data quality checks (FPAR 2.0 Data Quality Checks Overview, December 2025) that a clinic can run before
 * upload: shares of users with a value unknown, over the thresholds OPA warns at. Users are counted once, at
 * their latest encounter in the file, as OPA's person-level tables count them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class DataQuality
{
    /** What OPA checks, the share of users at which it warns, and when a user counts as unknown. */
    private const CHECKS = [
        ['label' => 'race unknown', 'threshold' => 0.10, 'unknown' => 'raceUnknown'],
        ['label' => 'ethnicity unknown', 'threshold' => 0.10, 'unknown' => 'blank:ethnicity'],
        ['label' => 'income unknown', 'threshold' => 0.05, 'unknown' => 'blank:annual_household_income'],
        ['label' => 'insurance unknown', 'threshold' => 0.05, 'unknown' => 'blank:insurance_coverage_type'],
        ['label' => 'limited English proficiency unknown', 'threshold' => 0.10, 'unknown' => 'blank:limited_english_proficiency'],
        ['label' => 'method at exit unknown', 'threshold' => 0.05, 'unknown' => 'methodUnknown'],
    ];

    /**
     * @param list<Row> $rows the rows written (not the refused ones)
     * @return list<array{label: string, unknown: int, users: int, share: float, threshold: float}> the checks over threshold
     */
    public static function warnings(array $rows, string $raceUnknownYes): array
    {
        $latest = [];
        foreach ($rows as $row) {
            $id = $row->cells['patient_id'];
            if (!isset($latest[$id]) || strcmp($row->cells['visit_date'], $latest[$id]->cells['visit_date']) >= 0) {
                $latest[$id] = $row;
            }
        }
        $users = count($latest);
        if ($users === 0) {
            return [];
        }
        $out = [];
        foreach (self::CHECKS as $check) {
            $unknown = 0;
            foreach ($latest as $row) {
                $c = $row->cells;
                $isUnknown = match ($check['unknown']) {
                    'raceUnknown' => $c['race_unknown'] === $raceUnknownYes,
                    'methodUnknown' => $c['contraceptive_method_exit'] === '' && $c['no_contraceptive_reason_exit'] === '',
                    default => $c[substr($check['unknown'], 6)] === '',
                };
                $unknown += $isUnknown ? 1 : 0;
            }
            $share = $unknown / $users;
            if ($share >= $check['threshold']) {
                $out[] = ['label' => $check['label'], 'unknown' => $unknown, 'users' => $users, 'share' => $share, 'threshold' => $check['threshold']];
            }
        }
        return $out;
    }
}
