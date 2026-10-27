<?php

/**
 * "Confidential visits" as the Staff Worklist collects it, without a database or the Worklist: the visits come from the
 * site's confidential categories, a claim to an insurer and a balance a statement would send to a guardian each make a
 * row, and nothing else does. The queries run for real in the end-to-end check (dev/title_x_report_check.py).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace {
    if (!function_exists('xl')) {
        function xl(string $text): string
        {
            return $text;
        }
    }
}

namespace OpenEMR\Common\Database {
    // Core's QueryUtils, standing in (the same one EncounterSourceTest declares): it answers each query from the
    // test's handler and records it.
    if (!class_exists(QueryUtils::class)) {
        final class QueryUtils
        {
            /** @var list<array{sql: string, binds: list<mixed>}> */
            public static array $queries = [];
            /** @var ?\Closure(string, list<mixed>): list<array<string, mixed>> */
            public static ?\Closure $answer = null;

            /**
             * @param list<mixed> $binds
             * @return list<array<string, mixed>>
             */
            public static function fetchRecords(string $sql, array $binds = []): array
            {
                self::$queries[] = ['sql' => $sql, 'binds' => $binds];
                return self::$answer === null ? [] : (self::$answer)($sql, $binds);
            }
        }
    }
}

namespace OpenHealthcare\TitleXReport\Tests {

    use OpenEMR\Common\Database\QueryUtils;
    use OpenHealthcare\Common\Contract\Worklist\WorkItem;
    use OpenHealthcare\TitleXReport\Worklist\ConfidentialVisitsSource;
    use PHPUnit\Framework\TestCase;

    final class ConfidentialVisitsSourceTest extends TestCase
    {
        private const NOW = '2026-10-27 09:00:00';
        private const CONFIDENTIAL = 12;

        protected function setUp(): void
        {
            QueryUtils::$queries = [];
            QueryUtils::$answer = null;
        }

        /**
         * The database: settings, visits, claims, the money on each visit and the patients.
         *
         * @param list<array<string, mixed>>                $visits
         * @param list<array<string, mixed>>                $claims
         * @param array<int, float>                         $charges  encounter => fee sheet total
         * @param array<int, float>                         $paid     encounter => payments and adjustments
         * @param list<array<string, mixed>>                $patients
         */
        private static function answer(array $confidential, array $visits, array $claims = [], array $charges = [], array $paid = [], array $patients = []): void
        {
            QueryUtils::$answer = static function (string $sql, array $binds) use ($confidential, $visits, $claims, $charges, $paid, $patients): array {
                if (str_contains($sql, 'title_x_report_setting')) {
                    return $binds[0] === 'confidential' ? [['value' => json_encode($confidential)]] : [];
                }
                if (str_contains($sql, 'FROM form_encounter')) {
                    return $visits;
                }
                if (str_contains($sql, 'FROM claims')) {
                    return $claims;
                }
                if (str_contains($sql, 'FROM billing')) {
                    return array_map(fn(int $e, float $a): array => ['encounter' => $e, 'amount' => $a], array_keys($charges), $charges);
                }
                if (str_contains($sql, 'FROM ar_activity')) {
                    return array_map(fn(int $e, float $a): array => ['encounter' => $e, 'amount' => $a], array_keys($paid), $paid);
                }
                if (str_contains($sql, 'FROM patient_data')) {
                    return $patients;
                }
                return [];
            };
        }

        /** @return array<string, mixed> */
        private static function visit(int $pid, int $encounter, string $sensitivity = ''): array
        {
            return ['pid' => $pid, 'encounter' => $encounter, 'date' => '2026-09-14 10:00:00', 'facility_id' => 3, 'sensitivity' => $sensitivity, 'category' => 'Confidential visit'];
        }

        /** @return array<string, mixed> */
        private static function patient(int $pid, string $guardian = ''): array
        {
            return ['pid' => $pid, 'fname' => 'Jo', 'lname' => "Doe$pid", 'DOB' => '2010-02-03', 'pubpid' => "P$pid", 'deceased_date' => null,
                'street' => '1 Main St', 'street_line_2' => '', 'city' => 'Town', 'state' => 'TX', 'postal_code' => '75001',
                'guardiansname' => $guardian, 'guardianaddress' => '', 'guardiancity' => '', 'guardianstate' => '', 'guardianpostalcode' => ''];
        }

        /** core's StatementRecipient::lines(), as 8.5.0 has it: the guardian's name when one is set */
        private static function core85(): \Closure
        {
            return static fn(array $r): array => [trim((string)$r['guardiansname']) !== '' ? trim((string)$r['guardiansname']) : trim($r['fname'] . ' ' . $r['lname'])];
        }

        /** @return list<WorkItem> */
        private static function collect(?\Closure $recipient = null, ?int $pid = null): array
        {
            return (new ConfidentialVisitsSource(new \DateTimeImmutable(self::NOW), recipient: $recipient))->collect($pid);
        }

        public function testNothingIsReadUntilTheSiteChoosesConfidentialCategories(): void
        {
            self::answer([], [self::visit(7, 701)], [['encounter' => 701, 'version' => 1, 'status' => 1, 'payer_type' => 1]]);
            self::assertSame([], self::collect());
            foreach (QueryUtils::$queries as $q) {
                self::assertStringNotContainsString('form_encounter', $q['sql'], 'no visit is read without the setting');
            }
        }

        public function testTheVisitsComeFromTheConfidentialCategoriesWithinAYear(): void
        {
            self::answer([self::CONFIDENTIAL, 14], []);
            self::collect(null, 7);
            $visits = array_values(array_filter(QueryUtils::$queries, fn(array $q): bool => str_contains($q['sql'], 'FROM form_encounter')));
            self::assertCount(1, $visits);
            self::assertStringContainsString('fe.pc_catid IN (?, ?)', $visits[0]['sql']);
            self::assertStringNotContainsString('sensitivity >', $visits[0]['sql'], 'sensitivity is passed on, never used to choose visits');
            self::assertSame([self::CONFIDENTIAL, 14, '2025-10-27', 7], $visits[0]['binds']);
        }

        public function testAClaimToAnInsurerMakesARowAndNamesNoPayer(): void
        {
            self::answer(
                [self::CONFIDENTIAL],
                [self::visit(7, 701, 'high'), self::visit(9, 901)],
                [
                    ['encounter' => 701, 'version' => 1, 'status' => 1, 'payer_type' => 1], // queued
                    ['encounter' => 901, 'version' => 1, 'status' => 1, 'payer_type' => 1],
                    ['encounter' => 901, 'version' => 2, 'status' => 2, 'payer_type' => 2], // billed, the latest version
                ],
                [],
                [],
                [self::patient(7), self::patient(9)],
            );
            $items = self::collect(self::core85());
            self::assertCount(2, $items);
            [$queued, $billed] = $items;
            self::assertSame('titlexconf:7:7011', (string)$queued->key);
            self::assertSame('Confidential visit queued to bill insurance', $queued->title);
            self::assertSame(WorkItem::PRIORITY_HIGH, $queued->priority, 'a claim still queued can be stopped');
            self::assertSame('high', $queued->sensitivity, "the visit's sensitivity is passed on for the Worklist to apply");
            self::assertStringContainsString('42 CFR 59.10(a)', $queued->detail);
            self::assertStringContainsString('Insurance level 1', $queued->detail);
            self::assertSame('Confidential visit billed to insurance', $billed->title);
            self::assertSame(WorkItem::PRIORITY_NORMAL, $billed->priority);
            self::assertStringContainsString('Insurance level 2', $billed->detail);
            foreach (QueryUtils::$queries as $q) {
                self::assertStringNotContainsString('insurance_companies', $q['sql'], 'no payer is looked up, so none can be named');
            }
        }

        public function testABalanceMakesARowOnlyWhenAStatementWouldReachSomeoneElse(): void
        {
            self::answer(
                [self::CONFIDENTIAL],
                [self::visit(7, 701), self::visit(8, 801), self::visit(9, 901), self::visit(10, 1001)],
                [],
                [701 => 40.0, 801 => 40.0, 901 => 40.0, 1001 => 40.0],
                [901 => 40.0],
                [self::patient(7, 'Pat Guardian'), self::patient(8), self::patient(9, 'Pat Guardian'), self::patient(10, '')],
            );
            $items = self::collect(self::core85());
            self::assertSame(['titlexconf:7:7012'], array_map(fn(WorkItem $i): string => (string)$i->key, $items),
                'only 7 owes and has statements addressed to a guardian: 8 and 10 get their own, 9 owes nothing');
            self::assertSame('Confidential visit with a balance: statements go to the guardian', $items[0]->title);
            self::assertStringContainsString('Balance 40.00', $items[0]->detail);
            self::assertStringContainsString('42 CFR 59.10', $items[0]->detail);
        }

        public function testBeforeCoreAddressedStatementsToTheGuardianAGuardianOnFileStillCounts(): void
        {
            self::answer([self::CONFIDENTIAL], [self::visit(7, 701), self::visit(8, 801)], [], [701 => 25.0, 801 => 25.0], [],
                [self::patient(7, 'Pat Guardian'), self::patient(8)]);
            // No recipient closure and no StatementRecipient class in the tests: an older core.
            $items = self::collect();
            self::assertSame(['titlexconf:7:7012'], array_map(fn(WorkItem $i): string => (string)$i->key, $items));
            self::assertSame('Confidential visit with a balance: a guardian is on file', $items[0]->title);
        }

        public function testAVisitCanHaveBothRowsEachDismissedOnItsOwn(): void
        {
            self::answer([self::CONFIDENTIAL], [self::visit(7, 701)], [['encounter' => 701, 'version' => 1, 'status' => 2, 'payer_type' => 1]],
                [701 => 60.0], [701 => 20.0], [self::patient(7, 'Pat Guardian')]);
            $items = self::collect(self::core85());
            self::assertSame(['titlexconf:7:7011', 'titlexconf:7:7012'], array_map(fn(WorkItem $i): string => (string)$i->key, $items));
            self::assertTrue($items[0]->dismissible && $items[1]->dismissible);
        }
    }
}
