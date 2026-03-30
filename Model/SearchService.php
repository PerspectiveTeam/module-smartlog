<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model;

class SearchService
{
    public function __construct(
        private readonly WorkerBridge $workerBridge,
        private readonly Config $config
    ) {
    }

    /**
     * Perform semantic search on indexed logs.
     *
     * @return array<int, array{file: string, date_from: string, date_to: string, lines: string, content: string}>
     */
    public function search(string $query, int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        // Enhance query with date context
        $enhancedQuery = $query;
        if ($dateFrom || $dateTo) {
            $dateParts = [];
            if ($dateFrom) {
                $dateParts[] = "from {$dateFrom}";
            }
            if ($dateTo) {
                $dateParts[] = "to {$dateTo}";
            }
            $enhancedQuery = $query . ' (date range: ' . implode(' ', $dateParts) . ')';
        }

        // Fetch more results than needed to allow post-filtering
        $fetchLimit = ($dateFrom || $dateTo) ? $limit * 3 : $limit;

        $workerResult = $this->workerBridge->execute('search', [
            'query' => $enhancedQuery,
            'limit' => $fetchLimit,
        ]);

        $results = $workerResult['results'] ?? [];

        // Post-filter by date range if specified
        if ($dateFrom || $dateTo) {
            $results = $this->filterByDate($results, $dateFrom, $dateTo);
        }

        $results = array_slice($results, 0, $limit);

        return $this->formatResults($results);
    }

    private function filterByDate(array $results, ?string $dateFrom, ?string $dateTo): array
    {
        return array_values(array_filter($results, function (array $item) use ($dateFrom, $dateTo) {
            $content = $item['content'] ?? '';
            if (!preg_match('/\[Date: ([^\]]+)\]/', $content, $matches)) {
                return true;
            }

            // Split on em-dash
            $dateParts = preg_split('/\s*\xe2\x80\x94\s*/', $matches[1], 2);
            if (!$dateParts || count($dateParts) < 2) {
                $dateParts = explode('-', $matches[1], 2);
            }

            $docDateFrom = trim($dateParts[0] ?? '');
            $docDateTo = trim($dateParts[1] ?? $docDateFrom);

            if ($dateFrom && $docDateTo < $dateFrom) {
                return false;
            }
            if ($dateTo && $docDateFrom > $dateTo) {
                return false;
            }

            return true;
        }));
    }

    private function formatResults(array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            $content = $item['content'] ?? '';
            $meta = $this->extractMetadata($content);
            $results[] = [
                'file' => $meta['file'] ?? ($item['sourceName'] ?? ''),
                'date_from' => $meta['date_from'] ?? '',
                'date_to' => $meta['date_to'] ?? '',
                'lines' => $meta['lines'] ?? '',
                'content' => $meta['content'] ?? $content,
            ];
        }

        return $results;
    }

    private function extractMetadata(string $content): array
    {
        $meta = [];

        if (preg_match('/\[File: ([^\]]+)\]/', $content, $m)) {
            $meta['file'] = $m[1];
        }
        if (preg_match('/\[Date: ([^\]]+)\]/', $content, $m)) {
            // Split on em-dash (UTF-8)
            $parts = preg_split('/\s*\xe2\x80\x94\s*/', $m[1], 2);
            if (!$parts || count($parts) < 2) {
                $parts = explode(' - ', $m[1], 2);
            }
            $meta['date_from'] = trim($parts[0] ?? '');
            $meta['date_to'] = trim($parts[1] ?? $meta['date_from']);
        }
        if (preg_match('/\[Lines: ([^\]]+)\]/', $content, $m)) {
            $meta['lines'] = $m[1];
        }
        if (preg_match('/---\n(.+)$/s', $content, $m)) {
            $meta['content'] = $m[1];
        }

        return $meta;
    }
}
