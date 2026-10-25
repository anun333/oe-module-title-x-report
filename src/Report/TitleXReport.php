<?php

/**
 * A year's report: every counted encounter as a row, the refused ones apart, what to fix, and OPA's data quality
 * warnings on what would be uploaded.
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

final class TitleXReport
{
    /**
     * @param list<Row>                                                                         $written
     * @param list<Row>                                                                         $refused
     * @param list<array{label: string, unknown: int, users: int, share: float, threshold: float}> $warnings
     */
    private function __construct(public readonly int $year, public readonly array $written, public readonly array $refused, public readonly array $warnings)
    {
    }

    /** @param list<Visit> $visits */
    public static function build(int $year, array $visits, Fpar $fpar): self
    {
        $builder = new RowBuilder($fpar);
        $written = [];
        $refused = [];
        foreach ($visits as $visit) {
            $row = $builder->build($visit);
            if ($row->refused()) {
                $refused[] = $row;
            } else {
                $written[] = $row;
            }
        }
        return new self($year, $written, $refused, DataQuality::warnings($written, (string)$fpar->codeByName('race_unknown', 'Yes')));
    }

    public function users(): int
    {
        return count(array_unique(array_map(fn(Row $r): string => $r->cells['patient_id'], $this->written)));
    }

    /** @return list<Row> written rows with something to fix */
    public function toFix(): array
    {
        return array_values(array_filter($this->written, fn(Row $r): bool => $r->toFix !== []));
    }
}
