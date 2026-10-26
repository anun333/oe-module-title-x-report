<?php

/**
 * HHS poverty guidelines by year and region, read from data/poverty-guidelines.json, for the Table 4 preview: a
 * family's guideline is the first person's amount plus each additional person's. A year or region whose figures
 * aren't in the file has none, and the preview says so rather than estimating.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Reference;

final class PovertyGuidelines
{
    /** HHS publishes one set for the 48 contiguous states and DC, and one each for Alaska and Hawaii. */
    public const REGIONS = ['contiguous', 'alaska', 'hawaii'];

    /** @param array<int, array<string, array{first: int, additional: int, source: string}>> $byYear */
    private function __construct(private readonly array $byYear)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__, 2) . '/data/poverty-guidelines.json';
        $data = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['guidelines'] ?? null)) {
            throw new \UnexpectedValueException("not a poverty guidelines file: $path");
        }
        $byYear = [];
        foreach ($data['guidelines'] as $g) {
            if (!is_int($g['firstPerson'] ?? null) || !is_int($g['eachAdditional'] ?? null) || !in_array($g['region'] ?? null, self::REGIONS, true)) {
                continue; // figures not filled in yet
            }
            $byYear[(int)$g['year']][(string)$g['region']] = ['first' => $g['firstPerson'], 'additional' => $g['eachAdditional'], 'source' => (string)($g['source'] ?? '')];
        }
        return new self($byYear);
    }

    public function has(int $year, string $region): bool
    {
        return isset($this->byYear[$year][$region]);
    }

    /** The guideline for a household of this size, or null when the year and region aren't loaded. */
    public function guideline(int $year, string $region, int $householdSize): ?int
    {
        $g = $this->byYear[$year][$region] ?? null;
        if ($g === null || $householdSize < 1) {
            return null;
        }
        return $g['first'] + ($householdSize - 1) * $g['additional'];
    }

    public function source(int $year, string $region): string
    {
        return $this->byYear[$year][$region]['source'] ?? '';
    }
}
