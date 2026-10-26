<?php

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The questionnaire queries join core's form_questionnaire_assessments.response_id, which core leaves unindexed.
 * install.sql and the upgrade file add one index, named response_id, under an #IfNotIndex guard; the UDS, Title X
 * and CCBHC Report modules share the name, so it's created once.
 */
final class ResponseIdIndexTest extends TestCase
{
    private const BLOCK = "#IfNotIndex form_questionnaire_assessments response_id\n"
        . "ALTER TABLE `form_questionnaire_assessments` ADD INDEX `response_id` (`response_id`(64));\n"
        . "#EndIf";

    /** @return array<string, array{string}> */
    public static function files(): array
    {
        return ['install' => ['install.sql'], 'upgrade' => ['0_2_1-to-0_2_2_upgrade.sql']];
    }

    /** @dataProvider files */
    #[\PHPUnit\Framework\Attributes\DataProvider('files')]
    public function testTheFileAddsTheSharedIndexUnderItsGuard(string $file): void
    {
        $sql = (string)file_get_contents(__DIR__ . '/../sql/' . $file);
        $this->assertStringContainsString(self::BLOCK, str_replace("\r\n", "\n", $sql));
    }

    public function testAnExistingInstallGetsItOnUpgrade(): void
    {
        // Module Manager runs an upgrade file when its from-version is at or above the site's sql_version, so the
        // file is named from the last release: every site installed at or before it runs it.
        $this->assertFileExists(__DIR__ . '/../sql/0_2_1-to-0_2_2_upgrade.sql');
    }
}
