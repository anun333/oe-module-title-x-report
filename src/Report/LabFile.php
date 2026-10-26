<?php

/**
 * OPA's lab result-level file, for a site that uploads two files instead of the combined one (FPAR 2.0 Sample
 * File, April 2026): one row per test result, with the visit's four keys, OPA's test type code and the result
 * code the combined file would carry in that test's result column. OPA: "Grantees who choose to report lab
 * results in a separate file must submit all lab results in the lab result-level file", and the encounter-level
 * file then carries every visit, with the tests performed but without their results.
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

final class LabFile
{
    /**
     * @param list<Row>             $rows      the rows the combined file carries (refused ones are skipped)
     * @param array<string, string> $testTypes test => OPA's name for its test type (data/fpar-tables.json)
     * @return list<array<string, string>> the lab layout's records, in visit order then OPA's test order
     */
    public static function records(array $rows, Fpar $fpar, array $testTypes): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row->refused()) {
                continue;
            }
            foreach (LabTests::TESTS as $test) {
                $result = $row->cells["test_{$test}_result"] ?? '';
                if ($result === '') {
                    continue;
                }
                $type = $fpar->codeByName('test_type', $testTypes[$test] ?? '');
                if ($type === null) {
                    throw new \UnexpectedValueException("OPA's reference file has no test type named {$testTypes[$test]}");
                }
                $out[] = ['facility_id' => $row->cells['facility_id'], 'patient_id' => $row->cells['patient_id'], 'visit_date' => $row->cells['visit_date'],
                    'birth_date' => $row->cells['birth_date'], 'test_type' => $type, 'test_result' => $result];
            }
        }
        return $out;
    }
}
