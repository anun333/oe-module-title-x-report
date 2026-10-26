<?php

/**
 * OPA's files: UTF-8, OPA's column names (its upload maps by name), dates as YYYY-MM-DD, empty cells empty.
 * Refused rows aren't written; the upload would reject the whole file over one.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class CsvFile
{
    /**
     * @param list<string> $columns
     * @param list<Row>    $rows
     */
    public static function write(array $columns, array $rows): string
    {
        return self::records($columns, array_map(fn(Row $row): array => $row->cells, array_values(array_filter($rows, fn(Row $row): bool => !$row->refused()))));
    }

    /**
     * Any of OPA's layouts from records keyed by column: the combined and encounter-level files from rows' cells,
     * the lab result-level file from LabFile.
     *
     * @param list<string>                $columns
     * @param list<array<string, string>> $records
     */
    public static function records(array $columns, array $records): string
    {
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            throw new \RuntimeException('no temporary stream for the CSV');
        }
        fputcsv($out, $columns, ',', '"', '');
        foreach ($records as $record) {
            fputcsv($out, array_map(fn(string $c): string => $record[$c] ?? '', $columns), ',', '"', '');
        }
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
