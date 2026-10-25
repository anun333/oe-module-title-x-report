<?php

/**
 * One encounter's FPAR 2.0 row: its cells by column, the keys it's missing (refused when any are), and what the
 * clinic should fix.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport\Report;

final class Row
{
    /**
     * @param array<string, string> $cells
     * @param list<string>          $missingKeys
     * @param list<array{key: string, args: list<string>}> $toFix see Problems
     */
    public function __construct(
        public readonly int $encounter,
        public readonly int $pid,
        public readonly array $cells,
        public readonly array $missingKeys,
        public readonly array $toFix,
    ) {
    }

    public function refused(): bool
    {
        return $this->missingKeys !== [];
    }
}
