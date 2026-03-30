<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model;

use Psr\Log\LoggerInterface;

class Indexer
{
    public function __construct(
        private readonly LogReader $logReader,
        private readonly WorkerBridge $workerBridge,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Reindex all log files into the vector store.
     *
     * @param callable|null $progressCallback Called with (int $processedCount) after each batch
     * @param callable|null $fileCallback Called with (string $relativePath) when starting a new file
     * @return int Total number of indexed chunks
     */
    public function reindex(?callable $progressCallback = null, ?callable $fileCallback = null): int
    {
        // Reset the index first
        $this->workerBridge->execute('reset');

        $batchSize = $this->config->getBatchSize();
        $chunks = [];
        $totalIndexed = 0;

        foreach ($this->logReader->readLogChunks($fileCallback) as $chunk) {
            $formattedContent = sprintf(
                "[File: %s] [Date: %s \xe2\x80\x94 %s] [Lines: %s]\n---\n%s",
                $chunk['file'],
                $chunk['date_from'],
                $chunk['date_to'],
                $chunk['lines'],
                $chunk['content']
            );

            $chunks[] = [
                'content' => $formattedContent,
                'file' => $chunk['file'],
            ];

            if (count($chunks) >= $batchSize) {
                $result = $this->workerBridge->execute('index', ['chunks' => $chunks]);
                $totalIndexed += $result['indexed'] ?? count($chunks);

                if ($progressCallback) {
                    $progressCallback($totalIndexed);
                }

                $chunks = [];
            }
        }

        // Flush remaining
        if (!empty($chunks)) {
            $result = $this->workerBridge->execute('index', ['chunks' => $chunks]);
            $totalIndexed += $result['indexed'] ?? count($chunks);

            if ($progressCallback) {
                $progressCallback($totalIndexed);
            }
        }

        return $totalIndexed;
    }
}
