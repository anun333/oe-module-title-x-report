<?php

/**
 * OPA's previous year check (FPAR 2.0 Data Quality Checks Overview, December 2025): for each cell of Tables 1 to
 * 9, 11 and 12, "did the reported number change by 50% or more from the previous year?". The preview runs it
 * against the prior year as OpenEMR builds it today, with the same settings, so it finds what changed in the
 * record; OPA compares with what was submitted last year, which can differ when the settings or the record did.
 * A cell that was 0 and isn't now is listed too, since no growth from 0 stays under 50 percent.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class PriorYear
{
    public const THRESHOLD = 0.5;

    /**
     * @return list<array{table: int, cell: string, row: string, column: string, prior: int, now: int, change: ?int}> change in
     *         whole percent, null when the prior year's figure was 0
     */
    public static function changes(Tables $now, Tables $prior): array
    {
        $out = [];
        foreach ($now->tables as $number => $table) {
            $before = isset($prior->tables[$number]) ? $prior->tables[$number]->cells() : [];
            foreach ($table->cells() as $ref => $value) {
                if (!array_key_exists($ref, $before)) {
                    continue; // not computed the year before (no guidelines that year, say)
                }
                $was = $before[$ref];
                if ($was === 0 && $value === 0) {
                    continue;
                }
                if ($was !== 0 && abs($value - $was) < self::THRESHOLD * $was) {
                    continue;
                }
                $r = (int)substr($ref, 1) - 1;
                $c = ord($ref[0]) - ord('A');
                $out[] = ['table' => $number, 'cell' => "T{$number}_$ref", 'row' => $table->rows[$r]['label'], 'column' => $table->columns[$c] ?? '',
                    'prior' => $was, 'now' => $value, 'change' => $was === 0 ? null : (int)round(($value - $was) * 100 / $was)];
            }
        }
        return $out;
    }
}
