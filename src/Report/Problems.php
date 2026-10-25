<?php

/**
 * What the report lists for the clinic to fix, as keys with their values. The page writes each one out in
 * words through OpenEMR's translation (Problems::text), so the wording lives in one place.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class Problems
{
    public const NO_VISIT_RECORD = 'no_visit_record';
    public const INVALID_ANSWER = 'invalid_answer';
    public const NO_PAYER_CODE = 'no_payer_code';
    public const UNKNOWN_COVERAGE = 'unknown_coverage';
    public const METHOD_NOT_PROVIDED = 'method_not_provided';
    public const METHOD_AND_REASON_INTAKE = 'method_and_reason_intake';
    public const METHOD_AND_REASON_EXIT = 'method_and_reason_exit';

    /**
     * @param array{key: string, args: list<string>} $problem
     * @param callable(string): string               $translate xl, or the identity in tests
     */
    public static function text(array $problem, callable $translate): string
    {
        $template = match ($problem['key']) {
            self::NO_VISIT_RECORD => 'No Title X visit record for this visit, so pregnancy status and intention, the methods and the counseling are blank',
            self::INVALID_ANSWER => 'The visit record answer %s is not one of the values OPA accepts for %s, so it is left blank',
            self::NO_PAYER_CODE => 'The primary insurance company has no Source of Payment Typology code: set one in Practice, Insurance Companies',
            self::UNKNOWN_COVERAGE => 'The payer code %s does not place the insurance as government, private or none',
            self::METHOD_NOT_PROVIDED => 'A method at exit, but not how it was provided',
            self::METHOD_AND_REASON_INTAKE => 'Both a method and a reason for no method at intake',
            self::METHOD_AND_REASON_EXIT => 'Both a method and a reason for no method at exit',
            default => 'Check this visit',
        };
        return vsprintf($translate($template), $problem['args']);
    }
}
