<?php

/**
 * One of OPA's FPAR tables as the preview shows it: its rows and columns in OPA's order, totals included, and a
 * cell left null where OPA's form says "Not applicable" or the figures can't be computed. Cells are addressed
 * as OPA's data quality checks name them: a column letter and a row number, so T7_B16 is cell('B16').
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class Table
{
    /**
     * @param list<string>                                     $columns column headings, A first
     * @param list<array{label: string, cells: list<?int>}>  $rows    rows 1 to n, the total row last
     */
    public function __construct(
        public readonly int $number,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly string $note = '',
    ) {
    }

    /** A cell by OPA's reference, such as 'J21'; null when it's not applicable or not computed. */
    public function cell(string $ref): ?int
    {
        if (preg_match('/^([A-Z])(\d+)$/', $ref, $m) !== 1) {
            throw new \InvalidArgumentException("not a cell reference: $ref");
        }
        $row = $this->rows[(int)$m[2] - 1] ?? throw new \InvalidArgumentException("no row {$m[2]} in Table {$this->number}");
        return $row['cells'][ord($m[1]) - ord('A')] ?? null;
    }

    /** @return array<string, int> every computed cell by reference, for comparing years */
    public function cells(): array
    {
        $out = [];
        foreach ($this->rows as $r => $row) {
            foreach ($row['cells'] as $c => $value) {
                if ($value !== null) {
                    $out[chr(ord('A') + $c) . ($r + 1)] = $value;
                }
            }
        }
        return $out;
    }
}
