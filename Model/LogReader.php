<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model;

use Magento\Framework\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

class LogReader
{
    private const LOG_ENTRY_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/';
    private const MAX_CHUNK_CHARS = 16000; // ~4K tokens, safe for 8K token embedding models
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Read log files and yield chunks as arrays.
     *
     * @return \Generator<int, array{content: string, file: string, date_from: string, date_to: string, lines: string}>
     */
    /**
     * @param callable|null $onFileStart Called with (string $relativePath) when starting a new file
     */
    public function readLogChunks(?callable $onFileStart = null): \Generator
    {
        $varDir = $this->directoryList->getPath('var');

        $logDir = $varDir . '/log';
        if (is_dir($logDir)) {
            yield from $this->readLogDirectory($logDir, $onFileStart);
        }

        $reportDir = $varDir . '/report';
        if (is_dir($reportDir)) {
            yield from $this->readReportDirectory($reportDir, $onFileStart);
        }
    }

    /**
     * Count total log files for progress estimation.
     */
    public function countLogFiles(): array
    {
        $varDir = $this->directoryList->getPath('var');
        $logCount = 0;
        $reportCount = 0;

        $logDir = $varDir . '/log';
        if (is_dir($logDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($logDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.log')) {
                    $logCount++;
                }
            }
        }

        $reportDir = $varDir . '/report';
        if (is_dir($reportDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($reportDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $reportCount++;
                }
            }
        }

        return ['log_files' => $logCount, 'report_files' => $reportCount, 'total' => $logCount + $reportCount];
    }

    private function readLogDirectory(string $dir, ?callable $onFileStart = null): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.log')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        foreach ($files as $filePath) {
            $this->logger->debug('SmartLog: Reading log file: ' . $filePath);
            if ($onFileStart) {
                $varDir = $this->directoryList->getPath('var');
                $onFileStart(str_replace($varDir . '/', '', $filePath));
            }
            yield from $this->readLogFile($filePath);
        }
    }

    private function readLogFile(string $filePath): \Generator
    {
        $chunkSize = $this->config->getChunkSize();
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            $this->logger->warning('SmartLog: Cannot open file: ' . $filePath);
            return;
        }

        try {
            $currentEntry = '';
            $entries = [];
            $entriesLength = 0;
            $entryDates = [];
            $lineStart = 1;
            $currentLine = 0;
            $firstDate = '';
            $lastDate = '';

            while (($line = fgets($handle)) !== false) {
                $currentLine++;

                if (preg_match(self::LOG_ENTRY_PATTERN, $line, $matches)) {
                    if ($currentEntry !== '') {
                        $entries[] = $currentEntry;
                        $entriesLength += strlen($currentEntry);
                        if (!empty($entryDates)) {
                            $lastDate = end($entryDates);
                        }
                    }

                    $entryDates[] = $matches[1];
                    if ($firstDate === '') {
                        $firstDate = $matches[1];
                    }

                    $currentEntry = $line;

                    if (count($entries) >= $chunkSize || $entriesLength >= self::MAX_CHUNK_CHARS) {
                        yield $this->createChunk(
                            implode('', $entries),
                            $filePath,
                            $firstDate,
                            $lastDate ?: $firstDate,
                            $lineStart,
                            $currentLine - 1
                        );
                        $entries = [];
                        $entriesLength = 0;
                        $entryDates = [$matches[1]];
                        $lineStart = $currentLine;
                        $firstDate = $matches[1];
                        $lastDate = '';
                    }
                } else {
                    $currentEntry .= $line;
                }
            }

            // Flush remaining entry
            if ($currentEntry !== '') {
                $entries[] = $currentEntry;
            }
            if (!empty($entries)) {
                if (!empty($entryDates)) {
                    $lastDate = end($entryDates);
                }
                yield $this->createChunk(
                    implode('', $entries),
                    $filePath,
                    $firstDate,
                    $lastDate ?: $firstDate,
                    $lineStart,
                    $currentLine
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private function readReportDirectory(string $dir, ?callable $onFileStart = null): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $chunkSize = $this->config->getChunkSize();
        $batch = [];
        $lastReportedFile = '';

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $filePath = $fileInfo->getPathname();

            if ($onFileStart && $filePath !== $lastReportedFile) {
                $varDir = $this->directoryList->getPath('var');
                $onFileStart(str_replace($varDir . '/', '', $filePath));
                $lastReportedFile = $filePath;
            }

            $content = @file_get_contents($filePath);
            if ($content === false || $content === '') {
                continue;
            }

            $date = date('Y-m-d H:i:s', (int) $fileInfo->getMTime());

            $batch[] = $this->createChunk(
                $content,
                $filePath,
                $date,
                $date,
                1,
                substr_count($content, "\n") + 1
            );

            if (count($batch) >= $chunkSize) {
                yield from $batch;
                $batch = [];
            }
        }

        if (!empty($batch)) {
            yield from $batch;
        }
    }

    /**
     * @return array{content: string, file: string, date_from: string, date_to: string, lines: string}
     */
    private function createChunk(
        string $content,
        string $filePath,
        string $dateFrom,
        string $dateTo,
        int $lineFrom,
        int $lineTo
    ): array {
        $varDir = $this->directoryList->getPath('var');
        $relativePath = str_replace($varDir . '/', '', $filePath);

        // Truncate oversized content to stay within embedding model token limits
        if (strlen($content) > self::MAX_CHUNK_CHARS) {
            $content = substr($content, 0, self::MAX_CHUNK_CHARS) . "\n... [truncated]";
        }

        return [
            'content' => $content,
            'file' => $relativePath,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'lines' => "{$lineFrom}-{$lineTo}",
        ];
    }
}
