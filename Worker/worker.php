<?php
/**
 * SmartLog Worker — Standalone process for LLPhant operations.
 *
 * Runs in a separate PHP process to avoid autoloading conflicts between
 * LLPhant's dependencies (psr/log v3, psr/http-message v2) and Magento 2.
 *
 * Protocol: reads JSON from stdin, writes JSON to stdout.
 *
 * Commands:
 *   - index:   embed + store documents in OpenSearch
 *   - search:  embed query + similarity search in OpenSearch
 *   - reset:   delete the OpenSearch index
 *
 * Usage:
 *   echo '{"command":"search","config":{...},"params":{...}}' | php worker.php
 */
declare(strict_types=1);

// Load isolated LLPhant dependencies — no Magento autoloader involved
require_once __DIR__ . '/vendor/autoload.php';

use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAIADA002EmbeddingGenerator;
use LLPhant\Embeddings\VectorStores\OpenSearch\OpenSearchVectorStore;
use LLPhant\OpenAIConfig;
use OpenSearch\ClientBuilder;

// ─── Entry point ───────────────────────────────────────────────────────────────

$input = file_get_contents('php://stdin');
if ($input === false || $input === '') {
    outputError('No input received on stdin');
}

$request = json_decode($input, true);
if (!is_array($request) || empty($request['command'])) {
    outputError('Invalid JSON input — "command" is required');
}

try {
    $config  = $request['config'] ?? [];
    $params  = $request['params'] ?? [];
    $command = $request['command'];

    $result = match ($command) {
        'index'  => handleIndex($config, $params),
        'search' => handleSearch($config, $params),
        'reset'  => handleReset($config),
        default  => throw new RuntimeException("Unknown command: {$command}"),
    };

    outputSuccess($result);
} catch (Throwable $e) {
    outputError($e->getMessage(), $e->getTraceAsString());
}

// ─── Command Handlers ──────────────────────────────────────────────────────────

function handleIndex(array $config, array $params): array
{
    $chunks    = $params['chunks'] ?? [];
    $batchSize = (int) ($config['batch_size'] ?? 20);

    if (empty($chunks)) {
        return ['indexed' => 0];
    }

    $generator  = createEmbeddingGenerator($config);
    $vectorStore = createVectorStore($config);

    // Pre-flight: test embedding works (embedText uses OpenAI SDK which throws on errors)
    try {
        $testEmbedding = $generator->embedText('connection test');
        if (empty($testEmbedding)) {
            throw new RuntimeException('Embedding returned empty result');
        }
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Embedding pre-flight check failed: ' . $e->getMessage() .
            '. Verify your API key and provider settings.'
        );
    }

    $documents = [];
    foreach ($chunks as $chunk) {
        $doc = new Document();
        $doc->content    = $chunk['content'];
        $doc->sourceType = 'log';
        $doc->sourceName = $chunk['file'] ?? '';
        $doc->hash       = md5($chunk['content']);
        $documents[]     = $doc;
    }

    $totalIndexed = 0;

    foreach (array_chunk($documents, $batchSize) as $batch) {
        try {
            $embedded = $generator->embedDocuments($batch);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Embedding generation failed: ' . $e->getMessage() .
                ' (provider: ' . ($config['provider'] ?? 'unknown') . ', model: ' . ($config['model'] ?? 'unknown') . ')'
            );
        }

        // LLPhant's embedDocuments may silently fail on HTTP errors (PSR-18 doesn't throw on 4xx/5xx).
        // Fall back to individual embedDocument calls which use OpenAI SDK (throws on errors).
        foreach ($embedded as $doc) {
            if (empty($doc->embedding)) {
                $generator->embedDocument($doc);
            }
        }

        $vectorStore->addDocuments($embedded);
        $totalIndexed += count($batch);

        // Stream progress to stderr (non-blocking for stdout JSON)
        fwrite(STDERR, json_encode(['progress' => $totalIndexed]) . "\n");
    }

    return ['indexed' => $totalIndexed];
}

function handleSearch(array $config, array $params): array
{
    $query = $params['query'] ?? '';
    $limit = (int) ($params['limit'] ?? 10);

    if ($query === '') {
        throw new RuntimeException('Search query is empty');
    }

    $generator  = createEmbeddingGenerator($config);
    $vectorStore = createVectorStore($config);
    $client     = createOpenSearchClient($config);
    $indexName  = $config['opensearch_index'] ?? 'smartlog_vectors';

    // 1) Keyword search — finds exact matches (order IDs, error codes, etc.)
    $keywordResults = keywordSearch($client, $indexName, $query, $limit);

    // 2) Semantic search — finds conceptually similar content
    $embedding = $generator->embedText($query);
    $semanticResults = $vectorStore->similaritySearch($embedding, $limit);

    // 3) Merge: keyword results first (exact matches are more relevant), then semantic, deduplicated
    $merged = [];
    $seenHashes = [];

    // Keyword matches go first
    foreach ($keywordResults as $doc) {
        $hash = $doc['hash'] ?? md5($doc['content'] ?? '');
        if (!isset($seenHashes[$hash])) {
            $seenHashes[$hash] = true;
            $merged[] = $doc;
        }
    }

    // Then semantic matches
    foreach ($semanticResults as $doc) {
        $hash = $doc->hash ?? md5($doc->content ?? '');
        if (!isset($seenHashes[$hash])) {
            $seenHashes[$hash] = true;
            $merged[] = [
                'content'    => $doc->content,
                'sourceType' => $doc->sourceType,
                'sourceName' => $doc->sourceName,
                'hash'       => $doc->hash,
            ];
        }
    }

    $merged = array_slice($merged, 0, $limit);

    return ['results' => $merged, 'total' => count($merged)];
}

/**
 * Full-text keyword search on the content field.
 */
function keywordSearch(\OpenSearch\Client $client, string $indexName, string $query, int $limit): array
{
    try {
        $response = $client->search([
            'index' => $indexName,
            'body' => [
                'size' => $limit,
                'query' => [
                    'bool' => [
                        'should' => [
                            // Exact phrase match (highest boost for IDs, codes, etc.)
                            [
                                'match_phrase' => [
                                    'content' => [
                                        'query' => $query,
                                        'boost' => 3,
                                    ],
                                ],
                            ],
                            // Individual terms match
                            [
                                'match' => [
                                    'content' => [
                                        'query' => $query,
                                        'operator' => 'and',
                                        'boost' => 2,
                                    ],
                                ],
                            ],
                            // Wildcard for partial matches (e.g. order ID inside a longer string)
                            [
                                'wildcard' => [
                                    'content' => [
                                        'value' => '*' . strtolower($query) . '*',
                                        'boost' => 1,
                                    ],
                                ],
                            ],
                        ],
                        'minimum_should_match' => 1,
                    ],
                ],
                'sort' => [
                    ['_score' => ['order' => 'desc']],
                ],
            ],
        ]);
    } catch (Throwable $e) {
        // Keyword search failure should not block semantic search
        return [];
    }

    $results = [];
    foreach ($response['hits']['hits'] ?? [] as $hit) {
        $results[] = [
            'content'    => $hit['_source']['content'] ?? '',
            'sourceType' => $hit['_source']['sourceType'] ?? '',
            'sourceName' => $hit['_source']['sourceName'] ?? '',
            'hash'       => $hit['_source']['hash'] ?? '',
        ];
    }

    return $results;
}

function handleReset(array $config): array
{
    $client    = createOpenSearchClient($config);
    $indexName = $config['opensearch_index'] ?? 'smartlog_vectors';

    try {
        if ($client->indices()->exists(['index' => $indexName])) {
            $client->indices()->delete(['index' => $indexName]);
        }
    } catch (Throwable $e) {
        // Index might not exist
    }

    return ['reset' => true];
}

// ─── Factories ─────────────────────────────────────────────────────────────────

function createEmbeddingGenerator(array $config): EmbeddingGeneratorInterface
{
    $provider = $config['provider'] ?? 'openai';
    $apiKey   = $config['api_key'] ?? '';
    $model    = $config['model'] ?? '';

    if ($apiKey === '') {
        throw new RuntimeException("API key for provider '{$provider}' is not configured");
    }

    return match ($provider) {
        'gemini'    => createGeminiGenerator($apiKey, $model, (int) ($config['embedding_length'] ?? 768)),
        'anthropic' => createVoyageGenerator($apiKey, $model, (int) ($config['embedding_length'] ?? 1024)),
        default     => createOpenAIGenerator($apiKey, $model),
    };
}

function createOpenAIGenerator(string $apiKey, string $model): EmbeddingGeneratorInterface
{
    $openAIConfig = new OpenAIConfig();
    $openAIConfig->apiKey = $apiKey;

    return match ($model) {
        'text-embedding-3-large' => new OpenAI3LargeEmbeddingGenerator($openAIConfig),
        'text-embedding-ada-002' => new OpenAIADA002EmbeddingGenerator($openAIConfig),
        default                  => new OpenAI3SmallEmbeddingGenerator($openAIConfig),
    };
}

/**
 * Gemini embedding via Google AI API.
 */
function createGeminiGenerator(string $apiKey, string $model, int $embeddingLength): EmbeddingGeneratorInterface
{
    return new class($apiKey, $model ?: 'text-embedding-004', $embeddingLength) implements EmbeddingGeneratorInterface {
        private \GuzzleHttp\Client $client;
        private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

        public function __construct(
            private readonly string $apiKey,
            private readonly string $model,
            private readonly int $embeddingLength
        ) {
            $this->client = new \GuzzleHttp\Client(['timeout' => 120]);
        }

        public function embedText(string $text): array
        {
            $url = self::API_BASE . $this->model . ':embedContent?key=' . $this->apiKey;
            $response = $this->client->post($url, [
                'json' => [
                    'model' => 'models/' . $this->model,
                    'content' => ['parts' => [['text' => $text]]],
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['embedding']['values'] ?? [];
        }

        public function embedDocument(Document $document): Document
        {
            $document->embedding = $this->embedText($document->content);
            return $document;
        }

        public function embedDocuments(array $documents): array
        {
            $requests = [];
            foreach ($documents as $document) {
                $requests[] = [
                    'model' => 'models/' . $this->model,
                    'content' => ['parts' => [['text' => $document->content]]],
                ];
            }
            $url = self::API_BASE . $this->model . ':batchEmbedContents?key=' . $this->apiKey;
            $allEmbeddings = [];
            foreach (array_chunk($requests, 100) as $chunk) {
                $response = $this->client->post($url, ['json' => ['requests' => $chunk]]);
                $data = json_decode($response->getBody()->getContents(), true);
                foreach ($data['embeddings'] ?? [] as $emb) {
                    $allEmbeddings[] = $emb['values'] ?? [];
                }
            }
            foreach ($documents as $i => $doc) {
                $doc->embedding = $allEmbeddings[$i] ?? [];
            }
            return $documents;
        }

        public function getEmbeddingLength(): int
        {
            return $this->embeddingLength;
        }
    };
}

/**
 * Voyage AI embedding (recommended by Anthropic).
 */
function createVoyageGenerator(string $apiKey, string $model, int $embeddingLength): EmbeddingGeneratorInterface
{
    return new class($apiKey, $model ?: 'voyage-3', $embeddingLength) implements EmbeddingGeneratorInterface {
        private \GuzzleHttp\Client $client;
        private const API_URL = 'https://api.voyageai.com/v1/embeddings';

        public function __construct(
            private readonly string $apiKey,
            private readonly string $model,
            private readonly int $embeddingLength
        ) {
            $this->client = new \GuzzleHttp\Client(['timeout' => 120]);
        }

        public function embedText(string $text): array
        {
            $response = $this->client->post(self::API_URL, [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'],
                'json' => ['input' => [$text], 'model' => $this->model],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['data'][0]['embedding'] ?? [];
        }

        public function embedDocument(Document $document): Document
        {
            $document->embedding = $this->embedText($document->content);
            return $document;
        }

        public function embedDocuments(array $documents): array
        {
            $texts = array_map(fn(Document $d) => $d->content, $documents);
            $allEmbeddings = [];
            foreach (array_chunk($texts, 128) as $chunk) {
                $response = $this->client->post(self::API_URL, [
                    'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'],
                    'json' => ['input' => $chunk, 'model' => $this->model],
                ]);
                $data = json_decode($response->getBody()->getContents(), true);
                foreach ($data['data'] ?? [] as $item) {
                    $allEmbeddings[] = $item['embedding'] ?? [];
                }
            }
            foreach ($documents as $i => $doc) {
                $doc->embedding = $allEmbeddings[$i] ?? [];
            }
            return $documents;
        }

        public function getEmbeddingLength(): int
        {
            return $this->embeddingLength;
        }
    };
}

function createVectorStore(array $config): OpenSearchVectorStore
{
    $client    = createOpenSearchClient($config);
    $indexName = $config['opensearch_index'] ?? 'smartlog_vectors';

    return new OpenSearchVectorStore($client, $indexName);
}

function createOpenSearchClient(array $config): \OpenSearch\Client
{
    $hostname = $config['opensearch_host'] ?? 'localhost';
    $port     = (int) ($config['opensearch_port'] ?? 9200);

    $protocol = parse_url($hostname, PHP_URL_SCHEME) ?: 'http';
    $hostname = (string) preg_replace('/https?:\/\//i', '', $hostname);

    $authString = '';
    if (!empty($config['opensearch_auth'])) {
        $user = $config['opensearch_username'] ?? '';
        $pass = $config['opensearch_password'] ?? '';
        if ($user && $pass) {
            $authString = urlencode($user) . ':' . urlencode($pass) . '@';
        }
    }

    $host = sprintf('%s://%s%s:%d', $protocol, $authString, $hostname, $port);
    $builder = ClientBuilder::create()->setHosts([$host]);

    if ($protocol === 'https') {
        $builder->setSSLVerification(false);
    }

    return $builder->build();
}

// ─── Output helpers ────────────────────────────────────────────────────────────

function outputSuccess(array $data): never
{
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit(0);
}

function outputError(string $message, string $trace = ''): never
{
    echo json_encode(['success' => false, 'error' => $message, 'trace' => $trace], JSON_UNESCAPED_UNICODE);
    exit(1);
}
