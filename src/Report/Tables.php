<?php

/**
 * OPA's FPAR tables, computed from the rows the file would carry, so a clinic sees what OPA's system will build
 * before it uploads: Tables 1 to 9, 11 and 12 (FPAR 2.0 Implementation Guide, September 2025, Appendix A).
 * Table 10 is retired; Tables 13 (staffing) and 14 (revenue) are entered in OPA's portal.
 *
 * Users are counted once, by patient_id, as OPA's system does:
 * - age as of June 30 of the year, from the birth date (Tables 1, 7, 8 and 11);
 * - sex, race, ethnicity, insurance and English proficiency from their latest visit in the year (the guide asks
 *   for "the most current" insurance and income);
 * - income from their latest visit that has income and household size;
 * - their primary method from their latest visit with a method at exit, as OPA's FAQ puts it ("most recent,
 *   nonmissing value for contraceptive method at exit"), else from their latest reason for no method.
 * Tests are counted per visit (Tables 9 and 12), and users tested once each (Tables 9 row 1 and 11).
 *
 * Rows are placed by OPA's names for its values (data/fpar-tables.json, our map), never by codes typed here.
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
use OpenHealthcare\TitleXReport\Reference\PovertyGuidelines;

final class Tables
{
    /** @param array<int, Table> $tables by OPA's number */
    private function __construct(public readonly int $year, public readonly array $tables)
    {
    }

    /** @param list<Row> $rows the rows the file carries (refused rows are left out by the caller or skipped here) */
    public static function build(int $year, array $rows, Fpar $fpar, PovertyGuidelines $guidelines, string $region, ?string $mapPath = null): self
    {
        $builder = new TablesBuilder($year, $fpar, self::loadMap($mapPath), $guidelines, $region);
        return new self($year, $builder->tables(array_values(array_filter($rows, fn(Row $r): bool => !$r->refused()))));
    }

    /** @return array<string, mixed> */
    public static function loadMap(?string $path = null): array
    {
        $path ??= dirname(__DIR__, 2) . '/data/fpar-tables.json';
        $map = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($map) || !is_array($map['methods'] ?? null)) {
            throw new \UnexpectedValueException("not an FPAR tables map: $path");
        }
        return $map;
    }

    public function table(int $number): Table
    {
        return $this->tables[$number] ?? throw new \InvalidArgumentException("no Table $number");
    }
}
