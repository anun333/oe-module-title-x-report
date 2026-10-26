<?php

declare(strict_types=1);

namespace OpenEMR\Common\Database {
    // Core's QueryUtils, standing in: it answers each query from the test's handler and records it.
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
    use OpenHealthcare\TitleXReport\Repository\EncounterSource;
    use PHPUnit\Framework\TestCase;

    /**
     * How the visit queries are put together, without a database: the visit a record belongs to, the
     * responses that count, and the patient columns a site may lack. The queries themselves run in the
     * end-to-end check (dev/title_x_report_check.py), against core's own save.
     */
    final class EncounterSourceTest extends TestCase
    {
        protected function setUp(): void
        {
            QueryUtils::$queries = [];
            QueryUtils::$answer = null;
        }

        /** The query that reads the visit records. */
        private static function recordsQuery(): array
        {
            foreach (QueryUtils::$queries as $query) {
                if (str_contains($query['sql'], 'FROM questionnaire_response AS qr')) {
                    return $query;
                }
            }
            self::fail('no visit record query was made');
        }

        public function testAnOptionalPatientColumnASiteLacksIsReadAsNull(): void
        {
            $all = EncounterSource::OPTIONAL_PATIENT_COLUMNS;
            self::assertSame('pd.language, pd.interpreter_needed, pd.ethnicity, pd.race, pd.monthly_income, pd.family_size', EncounterSource::patientSelect($all));
            // OpenEMR 7.0.2 and 7.0.3: interpreter_needed came with 7.0.4.
            $before704 = array_values(array_diff($all, ['interpreter_needed']));
            self::assertSame('pd.language, NULL AS interpreter_needed, pd.ethnicity, pd.race, pd.monthly_income, pd.family_size', EncounterSource::patientSelect($before704));
            self::assertSame(
                'NULL AS language, NULL AS interpreter_needed, NULL AS ethnicity, NULL AS race, NULL AS monthly_income, NULL AS family_size',
                EncounterSource::patientSelect([])
            );
        }

        public function testTheVisitQuerySelectsOnlyTheColumnsTheSiteHas(): void
        {
            QueryUtils::$answer = function (string $sql): array {
                if (str_contains($sql, 'information_schema.columns') && str_contains($sql, 'IN (')) {
                    return [['name' => 'language'], ['name' => 'ethnicity'], ['name' => 'race']];
                }
                return [];
            };
            (new EncounterSource())->visits(2026, [5], []);
            $visit = null;
            foreach (QueryUtils::$queries as $query) {
                if (str_contains($query['sql'], 'FROM form_encounter AS fe') && str_contains($query['sql'], 'pd.pubpid')) {
                    $visit = $query['sql'];
                }
            }
            self::assertNotNull($visit, 'the visit query ran');
            self::assertStringContainsString('NULL AS interpreter_needed', $visit);
            self::assertStringContainsString('pd.language', $visit);
            self::assertStringNotContainsString('pd.interpreter_needed', $visit);
        }

        public function testARecordsVisitIsItsFormsThenTheOneItNamesThenTheSameDay(): void
        {
            (new EncounterSource())->visits(2026, [5], []);
            $sql = self::recordsQuery()['sql'];
            self::assertStringContainsString("fo.formdir = 'questionnaire_assessments' AND fo.deleted = 0", $sql);
            self::assertStringContainsString('COALESCE(fo.encounter, named.encounter, (', $sql, 'the form first, then the stored number, then the day');
            self::assertStringContainsString('named.pid = qr.patient_id', $sql, 'a stored number counts only as one of the patient\'s visits');
            self::assertStringContainsString('SELECT MIN(sd.encounter) FROM form_encounter AS sd', $sql);
            self::assertStringContainsString('WHERE fe.date >= ? AND fe.date < ?', $sql, 'the year is the visit\'s');
            self::assertStringContainsString('df.deleted = 1', $sql, 'a deleted form\'s response stays out');
        }

        public function testBeforeAnyCompletedResponseEveryStatusButEnteredInErrorCounts(): void
        {
            QueryUtils::$answer = fn(string $sql): array => str_contains($sql, 'MIN(COALESCE(`last_updated`') ? [['since' => null]] : [];
            (new EncounterSource())->visits(2026, [5], []);
            $query = self::recordsQuery();
            self::assertStringContainsString("AND COALESCE(`qr`.`status`, '') <> 'entered-in-error'", $query['sql']);
            self::assertSame(['2026-01-01', '2027-01-01', '%86651-7%'], $query['binds']);
            self::assertSame(substr_count($query['sql'], '?'), count($query['binds']), 'one bind per placeholder');
        }

        public function testAfterTheFirstCompletedResponseOnlyFinishedOnesSavedLaterCount(): void
        {
            QueryUtils::$answer = fn(string $sql): array => str_contains($sql, 'MIN(COALESCE(`last_updated`') ? [['since' => '2026-11-02 09:15:00']] : [];
            (new EncounterSource())->visits(2026, [5], []);
            $query = self::recordsQuery();
            self::assertStringContainsString("IN ('completed', 'amended') OR", $query['sql']);
            self::assertSame(['2026-01-01', '2027-01-01', '%86651-7%', '2026-11-02 09:15:00'], $query['binds']);
            self::assertSame(substr_count($query['sql'], '?'), count($query['binds']), 'one bind per placeholder');
        }
    }
}
