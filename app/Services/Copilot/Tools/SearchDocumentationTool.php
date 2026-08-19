<?php

namespace App\Services\Copilot\Tools;

use App\Models\Merchant;

/**
 * Grounds the copilot's general "how does X work" answers in PayFlow's own docs
 * (openapi.yaml + storage/docs/*.md) instead of the model's own (possibly wrong,
 * possibly out of date) assumptions about how this specific API behaves.
 *
 * Deliberately plain case-insensitive substring search, not embeddings/vector
 * similarity - our doc set is small and was written with predictable terminology
 * (payment status names, "idempotency", "SSRF", etc. show up verbatim), so keyword
 * matching covers the realistic query shapes. It won't handle a paraphrased query that
 * shares no words with the source text - a real production version serving a large,
 * loosely-worded doc set would want embeddings for that. Not needed here.
 */
class SearchDocumentationTool implements CopilotTool
{
    public function name(): string
    {
        return 'searchDocumentation';
    }

    public function description(): string
    {
        return 'Search PayFlow\'s own API reference and integration documentation for a keyword or short phrase. Use this before answering general "how does X work" / "why does PayFlow do Y" questions, rather than guessing.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'A keyword or short phrase, e.g. "idempotency" or "refund lock".'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(Merchant $merchant, array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return ['error' => 'query must not be empty.'];
        }

        $results = [];

        foreach ($this->documentationFiles() as $path) {
            $content = @file_get_contents($path);

            // Found in code review: `! stripos(...)` treated a match at position 0 (the
            // very start of the file) as "no match", since stripos() returns int 0 there
            // and `!0` is true - the strongest possible match was silently skipped.
            if ($content === false || stripos($content, $query) === false) {
                continue;
            }

            $results[] = [
                'file' => basename($path),
                'excerpt' => $this->excerptAround($content, $query),
            ];

            if (count($results) >= 5) {
                break;
            }
        }

        return $results === []
            ? ['results' => [], 'message' => 'No documentation matched that query.']
            : ['results' => $results];
    }

    /**
     * @return array<int, string>
     */
    private function documentationFiles(): array
    {
        return [
            base_path('openapi.yaml'),
            ...glob(storage_path('docs/*.md')),
        ];
    }

    private function excerptAround(string $content, string $query): string
    {
        $position = stripos($content, $query);
        $start = max(0, $position - 200);
        $excerpt = trim(substr($content, $start, 400));

        return ($start > 0 ? '...' : '').$excerpt.'...';
    }
}
