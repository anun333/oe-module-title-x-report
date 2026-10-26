<?php

/**
 * The site's Title X settings: which visit categories are Title X visits, and which facilities are in the
 * project. OPA leaves "is this a family planning encounter" to the clinic and its project officer, so the report
 * asks rather than guesses.
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
use OpenHealthcare\TitleXReport\Reference\PovertyGuidelines;

final class Settings
{
    /** @return list<int> */
    public function categories(): array
    {
        return $this->ids('categories');
    }

    /** @return list<int> */
    public function facilities(): array
    {
        return $this->ids('facilities');
    }

    /** Which HHS poverty guidelines Table 4's preview uses: contiguous (the 48 states and DC), alaska or hawaii. */
    public function region(): string
    {
        $row = QueryUtils::fetchRecords('SELECT value FROM title_x_report_setting WHERE name = ?', ['region'])[0] ?? null;
        $region = json_decode((string)($row['value'] ?? '""'), true);
        return is_string($region) && in_array($region, PovertyGuidelines::REGIONS, true) ? $region : PovertyGuidelines::REGIONS[0];
    }

    /**
     * @param list<int> $categories
     * @param list<int> $facilities
     */
    public function save(array $categories, array $facilities, string $region, int $userId): void
    {
        foreach (['categories' => $categories, 'facilities' => $facilities] as $name => $ids) {
            QueryUtils::sqlStatementThrowException(
                'REPLACE INTO title_x_report_setting (name, value, updated_by) VALUES (?, ?, ?)',
                [$name, json_encode(array_values(array_unique(array_map('intval', $ids)))), $userId]
            );
        }
        QueryUtils::sqlStatementThrowException(
            'REPLACE INTO title_x_report_setting (name, value, updated_by) VALUES (?, ?, ?)',
            ['region', json_encode(in_array($region, PovertyGuidelines::REGIONS, true) ? $region : PovertyGuidelines::REGIONS[0]), $userId]
        );
    }

    /** @return list<int> */
    private function ids(string $name): array
    {
        $row = QueryUtils::fetchRecords('SELECT value FROM title_x_report_setting WHERE name = ?', [$name])[0] ?? null;
        $ids = json_decode((string)($row['value'] ?? '[]'), true);
        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids), fn(int $i): bool => $i > 0)) : [];
    }
}
