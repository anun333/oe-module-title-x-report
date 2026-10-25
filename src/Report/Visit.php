<?php

/**
 * What the report knows about one counted encounter, read by EncounterSource and turned into a row by RowBuilder.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class Visit
{
    /**
     * @param list<string>                                                                 $raceCodes     CDC codes of the patient's races (list notes)
     * @param array{annualIncome: ?float, familySize: ?int, declined: bool}|null           $assessment    Sliding Fee's assessment in force on the visit date
     * @param array{sop: string}|null                                                      $insurance     the visit date's primary insurance; null when none
     * @param array{bps: ?int, bpd: ?int, height: ?float, weight: ?float}|null             $vitals        the encounter's last Vitals form
     * @param array{title: string, code: string}|null                                      $smoking       the History's smoking status: its list title and code (SNOMED-CT:...)
     * @param array<string, string>|null                                                   $visitRecord   the visit record's answers by question code; null when none
     * @param list<string>                                                                 $orderCodes
     * @param list<array{code: string, text: string}>                                      $results
     */
    public function __construct(
        public readonly int $encounter,
        public readonly int $pid,
        public readonly string $visitDate,
        public readonly int $facilityId,
        public readonly string $facilityNpi,
        public readonly string $providerNpi,
        public readonly string $providerTaxonomy,
        public readonly string $pubpid,
        public readonly string $birthDate,
        public readonly string $sex,
        public readonly string $language,
        public readonly string $interpreterNeeded,
        public readonly ?string $ethnicityCode,
        public readonly array $raceCodes,
        public readonly ?float $monthlyIncome,
        public readonly ?int $familySize,
        public readonly ?array $assessment,
        public readonly ?array $insurance,
        public readonly ?array $vitals,
        public readonly ?array $smoking,
        public readonly ?array $visitRecord,
        public readonly bool $titleXCategory,
        public readonly array $orderCodes = [],
        public readonly array $results = [],
    ) {
    }
}
