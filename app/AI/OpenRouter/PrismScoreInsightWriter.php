<?php

namespace App\AI\OpenRouter;

use App\AI\Contracts\ScoreInsightWriter;
use App\AI\DataObjects\GeneratedInsight;
use App\AI\Local\RuleBasedScoreInsightWriter;
use App\AI\Support\AiJobContext;
use App\Models\FoodyScore;
use App\Services\AiJobLogger;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Prism-backed {@see ScoreInsightWriter} (spec §2, §14). ONE structured call
 * words every chosen candidate for the day in the single Foody voice.
 *
 * The LLM only phrases. The deterministic seed (rule-based templates) supplies
 * the focus keys, figures, priorities and structured inputs; the model's text
 * is matched back BY KEY, so it can neither invent a candidate, drop a figure
 * into a different insight, nor change the ranking. Any candidate the model
 * skips or mangles ships with its deterministic wording — never an error.
 */
class PrismScoreInsightWriter implements ScoreInsightWriter
{
    public function __construct(
        private readonly AiJobLogger $logger,
        private readonly RuleBasedScoreInsightWriter $seedWriter,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    public function word(FoodyScore $record, array $candidates): array
    {
        $seeds = $this->seedWriter->word($record, $candidates);

        if ($seeds === []) {
            return [];
        }

        return $this->logger->run('score_insights', function (AiJobContext $job) use ($record, $candidates, $seeds): array {
            $job->provider = $this->provider;
            $job->model = $this->model;

            $response = Prism::structured()
                ->using($this->provider, $this->model)
                ->withSchema($this->schema())
                ->withSystemPrompt($this->systemPrompt())
                ->withPrompt($this->userPrompt($record, $candidates, $seeds))
                ->asStructured();

            $job->inputTokens = $response->usage->promptTokens;
            $job->outputTokens = $response->usage->completionTokens;

            $phrasings = $this->phrasingsByKey($response->structured ?? []);
            $job->resultStatus = 'insights_worded';

            return array_map(function (GeneratedInsight $seed) use ($phrasings): GeneratedInsight {
                $phrasing = $phrasings[$seed->focusKey] ?? null;

                if ($phrasing === null) {
                    return $seed; // model skipped it → deterministic wording ships
                }

                return $seed->withPhrasing(
                    title: $phrasing['title'],
                    body: $phrasing['body'],
                    priority: $seed->priority, // ranking is the engine's, not the model's
                    provider: $this->provider,
                    model: $this->model,
                );
            }, $seeds);
        });
    }

    private function schema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'score_insights',
            description: 'Reworded titles and bodies for the given insight candidates.',
            properties: [
                new ArraySchema('insights', 'One entry per given candidate key.', new ObjectSchema(
                    name: 'insight',
                    description: 'A reworded insight for one candidate.',
                    properties: [
                        new StringSchema('key', 'The candidate key this wording is for — copied exactly.'),
                        new StringSchema('title', 'A short, calm headline. No exclamation marks, no emojis.'),
                        new StringSchema('body', 'One or two sentences phrasing ONLY the provided figures.'),
                    ],
                    requiredFields: ['key', 'title', 'body'],
                )),
            ],
            requiredFields: ['insights'],
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You word short daily nutrition observations for Foody, a food-logging instrument.

        Voice — one voice, always:
        - Calm, precise, quietly confident. An instrument reading, not a coach's pep talk.
        - Plain statements of what the numbers show, then at most one obvious next move.
        - No exclamation marks, no emojis, no praise-words ("great job"), no personas.

        Hard rules:
        - Every number is already computed and provided. Phrase those figures; NEVER invent, recompute or estimate a value.
        - Each wording must stay about its candidate's topic — never merge candidates or change what one is about.
        - Never moralise about foods. Nutrients and patterns only: no "bad food", no guilt, no judgement of a single meal.
        - Missing data is missing, not zero — never imply the user under-ate because logs are absent.
        - This is general, non-medical guidance. No medical, diagnostic or treatment claims.
        - Title plus one or two sentences per insight. Nothing else.
        PROMPT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, GeneratedInsight>  $seeds
     */
    private function userPrompt(FoodyScore $record, array $candidates, array $seeds): string
    {
        $payload = [
            'score' => [
                'value' => $record->score,
                'band' => $record->band,
                'display_state' => $record->display_state,
            ],
            'candidates' => array_map(fn (array $c, GeneratedInsight $seed) => [
                'key' => $c['key'],
                'reason_code' => $c['reason_code'],
                'kind' => $c['kind'],
                'data' => $c['data'],
                'seed_title' => $seed->title,
                'seed_body' => $seed->body,
            ], $candidates, $seeds),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
        Here are today's deterministic score context and the chosen insight candidates, each with a working draft:

        {$json}

        Reword each candidate's title and body more naturally in the Foody voice, keyed by its exact candidate key. Phrase only the provided figures.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, array{title: string, body: string}>
     */
    private function phrasingsByKey(array $structured): array
    {
        $out = [];

        foreach (($structured['insights'] ?? []) as $entry) {
            $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
            $title = is_string($entry['title'] ?? null) ? trim($entry['title']) : '';
            $body = is_string($entry['body'] ?? null) ? trim($entry['body']) : '';

            if ($key !== '' && $title !== '' && $body !== '') {
                $out[$key] = ['title' => $title, 'body' => $body];
            }
        }

        return $out;
    }
}
