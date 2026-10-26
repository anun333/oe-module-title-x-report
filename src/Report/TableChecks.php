<?php

/**
 * OPA's checks on the tables themselves (FPAR 2.0 Data Quality Checks Overview, December 2025), run on the
 * preview so a clinic can fix the record, or ready the comment OPA's system will ask for, before it uploads:
 * - the age checks: female sterilization under 20 (T7_A1, T7_B1, T7_C1), a female user relying on vasectomy
 *   under 18 (T7_A16, T7_B16), and vasectomy under 20 (T8_A1, T8_B1, T8_C1);
 * - the method shares: "Withdrawal or other" and "No method, other reason" at 10 percent or more, the method
 *   unknown at 5 percent or more, for all users of each sex and within each age group;
 * - race and ethnicity unknown for 10 percent or more of each sex (Tables 2 and 3), income for 5 percent or more
 *   (Table 4), insurance for 5 percent or more or every user in one insurance category (Table 5), English
 *   proficiency for 10 percent or more (Table 6);
 * - chlamydia users equal to gonorrhea tests (T11_A6 = T12_A1, T11_B6 = T12_B1), which OPA flags too.
 * The checks that compare Table 1's totals with the others hold by construction here, and Table 13 and 14's are
 * entered in OPA's portal.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class TableChecks
{
    public const STERILIZED_UNDER_20 = 'sterilized_under_20';
    public const VASECTOMY_UNDER_18 = 'vasectomy_under_18';
    public const VASECTOMY_UNDER_20 = 'vasectomy_under_20';
    public const SHARE = 'share';
    public const SHARE_IN_AGE = 'share_in_age';
    public const ONE_INSURANCE = 'one_insurance';
    public const CHLAMYDIA_EQUALS_GONORRHEA = 'chlamydia_equals_gonorrhea';

    private const AGE_COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];

    /** @return list<array{key: string, table: int, cells: string, args: list<string|int>}> */
    public static function run(Tables $tables): array
    {
        $out = [];
        $t7 = $tables->table(7);
        $t8 = $tables->table(8);
        $out = [...$out, ...self::anyOver(self::STERILIZED_UNDER_20, $t7, ['A1', 'B1', 'C1'])];
        $out = [...$out, ...self::anyOver(self::VASECTOMY_UNDER_18, $t7, ['A16', 'B16'])];
        $out = [...$out, ...self::anyOver(self::VASECTOMY_UNDER_20, $t8, ['A1', 'B1', 'C1'])];

        foreach ([[2, 'D7', 'D8', 0.10, 'race unknown or not reported'], [2, 'C8', 'D8', 0.10, 'ethnicity unknown or not reported'],
                  [3, 'D7', 'D8', 0.10, 'race unknown or not reported'], [3, 'C8', 'D8', 0.10, 'ethnicity unknown or not reported'],
                  [4, 'A6', 'A7', 0.05, 'income unknown or not reported'], [5, 'A4', 'A5', 0.05, 'insurance unknown or not reported'],
                  [6, 'A3', 'A4', 0.10, 'English proficiency unknown or not reported'],
                  [7, 'J15', 'J21', 0.10, 'Withdrawal or other method'], [7, 'J19', 'J21', 0.10, 'No method, other reason'], [7, 'J20', 'J21', 0.05, 'method unknown or not reported'],
                  [8, 'J5', 'J10', 0.10, 'Withdrawal or other method'], [8, 'J8', 'J10', 0.10, 'No method, other reason'], [8, 'J9', 'J10', 0.05, 'method unknown or not reported']] as [$n, $part, $whole, $threshold, $what]) {
            $check = self::share($tables->table($n), $part, $whole, $threshold, $what);
            if ($check !== null) {
                $out[] = $check;
            }
        }
        foreach ([[$t7, 20, 21], [$t8, 9, 10]] as [$table, $unknownRow, $totalRow]) {
            foreach (self::AGE_COLUMNS as $i => $column) {
                $part = $table->cell("$column$unknownRow");
                $whole = $table->cell("$column$totalRow");
                if ($part !== null && $whole !== null && $whole > 0 && $part / $whole >= 0.05) {
                    $out[] = ['key' => self::SHARE_IN_AGE, 'table' => $table->number, 'cells' => "T{$table->number}_$column$unknownRow / T{$table->number}_$column$totalRow",
                        'args' => [$table->columns[$i], $part, $whole, (int)round($part * 100 / $whole), 5]];
                }
            }
        }
        $t5 = $tables->table(5);
        $users = $t5->cell('A5') ?? 0;
        foreach (['A1', 'A2', 'A3'] as $ref) {
            if ($users > 0 && $t5->cell($ref) === $users) {
                $out[] = ['key' => self::ONE_INSURANCE, 'table' => 5, 'cells' => "T5_$ref / T5_A5", 'args' => [$t5->rows[(int)substr($ref, 1) - 1]['label']]];
            }
        }
        $t11 = $tables->table(11);
        $t12 = $tables->table(12);
        foreach ([['A', 'female'], ['B', 'male']] as [$column, $sex]) {
            $users = $t11->cell("{$column}6");
            if ($users !== null && $users > 0 && $users === $t12->cell("{$column}1")) {
                $out[] = ['key' => self::CHLAMYDIA_EQUALS_GONORRHEA, 'table' => 11, 'cells' => "T11_{$column}6 = T12_{$column}1", 'args' => [$sex, $users]];
            }
        }
        return $out;
    }

    /**
     * @param array{key: string, table: int, cells: string, args: list<string|int>} $check
     * @param callable(string): string $translate xl, or the identity in tests
     */
    public static function text(array $check, callable $translate): string
    {
        $template = match ($check['key']) {
            self::STERILIZED_UNDER_20 => 'Female sterilization as the primary method for %2$d female users under 20 (%1$s)',
            self::VASECTOMY_UNDER_18 => 'Vasectomy as the primary method for %2$d female users under 18 (%1$s)',
            self::VASECTOMY_UNDER_20 => 'Vasectomy as the primary method for %2$d male users under 20 (%1$s)',
            self::SHARE => '%1$s: %2$d of %3$d (%4$d percent; OPA flags %5$d percent or more)',
            self::SHARE_IN_AGE => 'Method unknown or not reported, ages %1$s: %2$d of %3$d (%4$d percent; OPA flags %5$d percent or more)',
            self::ONE_INSURANCE => 'Every user is in one insurance category: %s',
            self::CHLAMYDIA_EQUALS_GONORRHEA => 'The %1$s users tested for chlamydia equal the %1$s gonorrhea tests (%2$d), which OPA flags for a comment',
            default => 'Check this table',
        };
        $args = $check['args'];
        if ($check['key'] === self::SHARE) {
            $args[0] = $translate((string)$args[0]);
        }
        return vsprintf($translate($template), $args);
    }

    /**
     * @param list<string> $refs
     * @return list<array{key: string, table: int, cells: string, args: list<string|int>}>
     */
    private static function anyOver(string $key, Table $table, array $refs): array
    {
        $over = array_filter($refs, fn(string $ref): bool => ($table->cell($ref) ?? 0) > 0);
        if ($over === []) {
            return [];
        }
        $count = array_sum(array_map(fn(string $ref): int => (int)$table->cell($ref), $over));
        return [['key' => $key, 'table' => $table->number, 'cells' => implode(', ', array_map(fn(string $r): string => "T{$table->number}_$r", $over)), 'args' => [implode(', ', array_map(fn(string $r): string => "T{$table->number}_$r", $over)), $count]]];
    }

    /** @return array{key: string, table: int, cells: string, args: list<string|int>}|null */
    private static function share(Table $table, string $part, string $whole, float $threshold, string $what): ?array
    {
        $p = $table->cell($part);
        $w = $table->cell($whole);
        if ($p === null || $w === null || $w === 0 || $p / $w < $threshold) {
            return null;
        }
        return ['key' => self::SHARE, 'table' => $table->number, 'cells' => "T{$table->number}_$part / T{$table->number}_$whole",
            'args' => [$what, $p, $w, (int)round($p * 100 / $w), (int)round($threshold * 100)]];
    }
}
