<?php

/**
 * FPAR 2.0's six tests (data elements 29 to 40) from an encounter's lab orders and results.
 *
 * Performed: FPAR takes the ordered test's LOINC code as "performed" when it's one of OPA's codes for that test,
 * so the encounter's order codes are read first, then its result codes (a panel ordered under a local code
 * reports each test under its own LOINC code). Nothing found is left blank: "not performed" would be a claim the
 * record doesn't make.
 *
 * Result: the result row whose code is one of the test's codes, its text read as one of OPA's qualifiers, or for
 * a Pap test as a Bethesda category. The words matched are OPA's own names for its values, looked up in the
 * reference file, never codes typed here. A combined chlamydia and gonorrhea test reports each under its own
 * code, so it counts for both.
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

final class LabTests
{
    public const TESTS = ['pap', 'hpv', 'chlamydia', 'gonorrhea', 'hiv', 'syphilis'];
    private const LOINC = 'http://loinc.org';

    /**
     * Words in a result, in the order they're tried (the longer phrase first: "not detected" before "detected"),
     * each with the start of OPA's name for the qualifier it means.
     */
    private const QUALIFIERS = [
        '/\bnon[- ]?reactive\b/' => 'Non-Reactive', '/\bnot detected\b/' => 'Not detected', '/\bdetected\b/' => 'Detected',
        '/\bnegative\b/' => 'Negative', '/\bpositive\b/' => 'Positive', '/\bequivocal\b/' => 'Equivocal',
        '/\bindeterminate\b/' => 'Indeterminate', '/\binconclusive\b/' => 'Inconclusive', '/\breactive\b/' => 'Reactive',
    ];

    /** Bethesda categories in a Pap result, most specific first, with the start of OPA's name for each. */
    private const BETHESDA = [
        '/\basc-?h\b|cannot exclude/' => 'Atypical squamous cells, cannot exclude', '/\basc-?us\b|undetermined significance|uncertain significance/' => 'Atypical squamous cells of uncertain',
        '/\blsil\b|low[- ]grade/' => 'Low-grade squamous', '/\bhsil\b|high[- ]grade/' => 'High-grade squamous',
        '/squamous cell carcinoma/' => 'Squamous cell carcinoma', '/agc.*favou?r neoplastic|favou?r neoplastic/' => 'Atypical glandular cells, favor',
        '/\bagc\b|atypical glandular/' => 'Atypical glandular cells on', '/\bais\b|adenocarcinoma in situ/' => 'Adenocarcinoma in situ',
        '/\bnilm\b|negative for intraepithelial/' => 'Negative for intraepithelial', '/unsatisfactory/' => 'Specimen unsatisfactory',
        '/satisfactory/' => 'Specimen satisfactory',
    ];

    public function __construct(private readonly Fpar $fpar)
    {
    }

    /**
     * @param list<string>                             $orderCodes the encounter's ordered procedure codes
     * @param list<array{code: string, text: string}> $results    its result rows: LOINC code and the result as written
     * @return array<string, string> the twelve test columns
     */
    public function columns(array $orderCodes, array $results): array
    {
        $out = [];
        foreach (self::TESTS as $test) {
            $codes = $this->fpar->codes("test_$test", self::LOINC);
            $performed = '';
            foreach ([...$orderCodes, ...array_column($results, 'code')] as $code) {
                if (in_array($code, $codes, true)) {
                    $performed = $code;
                    break;
                }
            }
            $result = '';
            foreach ($results as $row) {
                if (in_array($row['code'], $codes, true) && ($result = $this->result($test, $row['text'])) !== '') {
                    break;
                }
            }
            $out["test_$test"] = $performed;
            $out["test_{$test}_result"] = $result;
        }
        return $out;
    }

    /** OPA's code for a result as written, or '' when it reads as none of them. */
    public function result(string $test, string $text): string
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return '';
        }
        foreach ($test === 'pap' ? self::BETHESDA : self::QUALIFIERS as $pattern => $name) {
            if (preg_match($pattern, $text) === 1) {
                return $this->fpar->codesNamedFrom("test_{$test}_result", $name)[0] ?? '';
            }
        }
        return '';
    }
}
