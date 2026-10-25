<?php

/**
 * A Title X visit record's answers, by FPAR's question codes. The visit record is Clinic Forms' coded document
 * saved as an OpenEMR questionnaire: each question carries its LOINC code in the Questionnaire, and each chosen
 * answer its code in the QuestionnaireResponse. The response names its items only by linkId, so the
 * Questionnaire is read to turn linkIds into codes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class VisitRecord
{
    private const LOINC = 'http://loinc.org';

    /** FPAR's questions a visit record answers (data elements 15 to 23 and 41). */
    public const QUESTIONS = ['82810-3', '86645-9', '86649-1', '86650-9', '86651-7', '86653-3', '86652-5', '86654-1', '86655-8', '98076-3'];

    /** How many of those a questionnaire must carry to count as a visit record, so another form that asks one isn't. */
    private const AT_LEAST = 3;

    /**
     * The answers by question code, or null when the questionnaire isn't a visit record.
     *
     * @return array<string, string>|null
     */
    public static function answers(string $questionnaireJson, string $responseJson): ?array
    {
        $questionnaire = json_decode($questionnaireJson, true);
        $response = json_decode($responseJson, true);
        if (!is_array($questionnaire) || !is_array($response)) {
            return null;
        }
        $codeOf = [];
        self::questionCodes($questionnaire['item'] ?? [], $codeOf);
        if (count(array_intersect($codeOf, self::QUESTIONS)) < self::AT_LEAST) {
            return null;
        }
        $answers = [];
        self::chosen($response['item'] ?? [], $codeOf, $answers);
        return $answers;
    }

    /**
     * @param mixed                 $items
     * @param array<string, string> $codeOf linkId => LOINC question code
     */
    private static function questionCodes(mixed $items, array &$codeOf): void
    {
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array)($item['code'] ?? []) as $coding) {
                if (is_array($coding) && ($coding['system'] ?? null) === self::LOINC && in_array($coding['code'] ?? null, self::QUESTIONS, true)) {
                    $codeOf[(string)($item['linkId'] ?? '')] = (string)$coding['code'];
                }
            }
            self::questionCodes($item['item'] ?? [], $codeOf);
        }
    }

    /**
     * Each coded question's chosen answer. Children are read under an item and under its answers, since a
     * response may nest a question's children either way (R4's qrs-1 puts them under the answer).
     *
     * @param mixed                 $items
     * @param array<string, string> $codeOf
     * @param array<string, string> $answers
     */
    private static function chosen(mixed $items, array $codeOf, array &$answers): void
    {
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = $codeOf[(string)($item['linkId'] ?? '')] ?? null;
            foreach ((array)($item['answer'] ?? []) as $answer) {
                if (!is_array($answer)) {
                    continue;
                }
                $coding = $answer['valueCoding'] ?? null;
                if ($question !== null && !isset($answers[$question]) && is_array($coding) && is_string($coding['code'] ?? null) && $coding['code'] !== '') {
                    $answers[$question] = $coding['code'];
                }
                self::chosen($answer['item'] ?? [], $codeOf, $answers);
            }
            self::chosen($item['item'] ?? [], $codeOf, $answers);
        }
    }
}
