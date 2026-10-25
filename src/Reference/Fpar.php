<?php

/**
 * FPAR 2.0's columns and valid values, read from data/fpar2.json (generated from OPA's own workbook by
 * tools/fpar-reference.py). Codes are looked up here by column, or by the name OPA gives them, so no code is
 * typed into the report's rules.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Reference;

final class Fpar
{
    /**
     * @param list<string>                                                                                     $columns
     * @param array<string, array{question: ?string, values: list<array{system: string, code: string, display: string, transition: bool}>}> $byColumn
     */
    private function __construct(public readonly string $version, private readonly array $columns, private readonly array $byColumn)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__, 2) . '/data/fpar2.json';
        $data = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['elements'] ?? null) || !is_array($data['layouts']['combined'] ?? null)) {
            throw new \UnexpectedValueException("not an FPAR reference file: $path");
        }
        $byColumn = [];
        foreach ($data['elements'] as $element) {
            $values = [];
            foreach ($element['values'] as $v) {
                $values[] = ['system' => (string)$v['system'], 'code' => (string)$v['code'], 'display' => (string)$v['display'], 'transition' => (bool)$v['transition']];
            }
            $byColumn[(string)$element['column']] = ['question' => isset($element['question']) ? (string)$element['question'] : null, 'values' => $values];
        }
        return new self((string)$data['source']['valid_values']['version'], array_map('strval', $data['layouts']['combined']), $byColumn);
    }

    /** @return list<string> the combined file's columns, in OPA's sample order */
    public function columns(): array
    {
        return $this->columns;
    }

    public function question(string $column): ?string
    {
        return $this->byColumn[$column]['question'] ?? null;
    }

    /** The column whose question has this LOINC code, among the ones a visit record answers. */
    public function columnForQuestion(string $loinc): ?string
    {
        foreach ($this->byColumn as $column => $element) {
            if ($element['question'] === $loinc) {
                return $column;
            }
        }
        return null;
    }

    public function valid(string $column, string $code): bool
    {
        foreach ($this->byColumn[$column]['values'] ?? [] as $v) {
            if ($v['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    public function current(string $column, string $code): bool
    {
        foreach ($this->byColumn[$column]['values'] ?? [] as $v) {
            if ($v['code'] === $code && !$v['transition']) {
                return true;
            }
        }
        return false;
    }

    /**
     * The code OPA names this way for the column: the display compared without case and without a SNOMED
     * semantic tag such as "(finding)". A current value is preferred to a transition one with the same name.
     */
    public function codeByName(string $column, string $name): ?string
    {
        $wanted = self::plain($name);
        $found = null;
        foreach ($this->byColumn[$column]['values'] ?? [] as $v) {
            if (self::plain($v['display']) === $wanted) {
                if (!$v['transition']) {
                    return $v['code'];
                }
                $found ??= $v['code'];
            }
        }
        return $found;
    }

    /**
     * Values of a column whose name starts with the given text, for result names such as "Atypical glandular cells".
     *
     * @return list<string>
     */
    public function codesNamedFrom(string $column, string $start, bool $current = true): array
    {
        $start = self::plain($start);
        $out = [];
        foreach ($this->byColumn[$column]['values'] ?? [] as $v) {
            if ((!$current || !$v['transition']) && str_starts_with(self::plain($v['display']), $start)) {
                $out[] = $v['code'];
            }
        }
        return $out;
    }

    /** @return list<string> the column's codes in one system, current ones only unless asked */
    public function codes(string $column, string $system, bool $current = true): array
    {
        $out = [];
        foreach ($this->byColumn[$column]['values'] ?? [] as $v) {
            if ($v['system'] === $system && (!$current || !$v['transition'])) {
                $out[] = $v['code'];
            }
        }
        return $out;
    }

    private static function plain(string $name): string
    {
        $name = preg_replace('/\s*\((finding|occupation|qualifier value|morphologic abnormality|procedure|regime\/therapy|observable entity|situation|disorder|physical object|product|record artifact)\)\s*$/i', '', $name) ?? $name;
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }
}
