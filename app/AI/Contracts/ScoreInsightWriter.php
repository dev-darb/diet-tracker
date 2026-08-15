<?php

namespace App\AI\Contracts;

use App\AI\DataObjects\GeneratedInsight;
use App\Models\FoodyScore;

/**
 * Words the Foody Score's chosen insight candidates (spec §2, §14). The
 * candidates arrive fully ranked and reason-coded from the deterministic
 * engine; a writer may only phrase them — never add, reorder, re-rank or
 * invent one. One implementation is deterministic templates, the other an
 * LLM re-phrasing seeded by those templates.
 */
interface ScoreInsightWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $candidates  the engine's chosen candidates, in rank order
     * @return array<int, GeneratedInsight>  one worded insight per candidate, same order
     */
    public function word(FoodyScore $record, array $candidates): array;
}
