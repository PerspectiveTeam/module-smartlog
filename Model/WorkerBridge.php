<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

/**
 * Bridge to the standalone LLPhant worker process.
 *
 * Executes lib/perspective-smartlog/worker.php in a separate PHP process,
 * passing config + params as JSON via stdin and reading results from stdout.
 * This avoids all autoloading conflicts between LLPhant deps and Magento 2.
 */
class WorkerBridge
{
    public function __construct(
        private readonly Config $config,
        private readonly DirectoryList $directoryList,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Call the worker with a command and parameters.
     *
     * @param string $command  One of: index, search, reset
     * @param array  $params   Command-specific parameters
     * @param callable|null $onProgress  Called with progress data from stderr
     * @return array Worker response data
     * @throws \RuntimeException on worker failure
     */
    public function execute(string $command, array $params = [], ?callable $onProgress = null): array
    {
        $workerPath = $this->getWorkerPath();
        $phpBin = $this->getPhpCliBinary();

        $payload = json_encode([
            'command' => $command,
            'config'  => $this->buildConfigPayload(),
            'params'  => $params,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr (progress)
        ];

        $process = proc_open(
            [$phpBin, $workerPath],
            $descriptors,
            $pipes,
            dirname($workerPath)
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start SmartLog worker process');
        }

        // Write payload to stdin and close
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        // Read stderr for progress (non-blocking)
        stream_set_blocking($pipes[2], false);

        // Read stdout (result)
        $stdout = '';
        $stderr = '';

        // Read both streams until process finishes
        while (true) {
            $status = proc_get_status($process);

            // Read any available stderr (progress lines)
            while (($line = fgets($pipes[2])) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    $progressData = json_decode($line, true);
                    if ($onProgress !== null && is_array($progressData)) {
                        $onProgress($progressData);
                    }
                    $stderr .= $line . "\n";
                }
            }

            // Read stdout chunk
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false) {
                $stdout .= $chunk;
            }

            if (!$status['running']) {
                // Read remaining data
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            usleep(10000); // 10ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($stdout === '') {
            $this->logger->error('SmartLog worker returned no output', [
                'exit_code' => $exitCode,
                'stderr' => substr($stderr, 0, 1000),
                'php_binary' => $phpBin,
            ]);
            throw new \RuntimeException(
                'SmartLog worker returned no output. Exit code: ' . $exitCode
            );
        }

        $result = json_decode($stdout, true);

        if (!is_array($result)) {
            $this->logger->error('SmartLog worker invalid JSON output', [
                'stdout' => substr($stdout, 0, 1000),
                'stderr' => substr($stderr, 0, 1000),
                'exit_code' => $exitCode,
                'php_binary' => $phpBin,
            ]);
            throw new \RuntimeException('SmartLog worker returned invalid JSON');
        }

        if (empty($result['success'])) {
            $errorMsg = $result['error'] ?? 'Unknown worker error';
            $this->logger->error('SmartLog worker error: ' . $errorMsg, [
                'trace' => $result['trace'] ?? '',
            ]);
            throw new \RuntimeException('SmartLog worker: ' . $errorMsg);
        }

        return $result['data'] ?? [];
    }

    private function buildConfigPayload(): array
    {
        return [
            'provider'           => $this->config->getProvider(),
            'api_key'            => $this->config->getApiKey(),
            'model'              => $this->config->getModel(),
            'embedding_length'   => $this->config->getEmbeddingLength(),
            'batch_size'         => $this->config->getBatchSize(),
            'opensearch_host'    => $this->config->getOpenSearchHost(),
            'opensearch_port'    => $this->config->getOpenSearchPort(),
            'opensearch_auth'    => $this->config->isOpenSearchAuthEnabled(),
            'opensearch_username' => $this->config->getOpenSearchUsername(),
            'opensearch_password' => $this->config->getOpenSearchPassword(),
            'opensearch_index'   => $this->config->getOpenSearchIndexName(),
        ];
    }

    private function getWorkerPath(): string
    {
        // Worker is installed to <magento-root>/lib/smartlog/ to avoid DI compile scanning
        $magentoRoot = $this->directoryList->getRoot();
        $path = $magentoRoot . '/lib/smartlog/worker.php';

        if (!is_file($path)) {
            // Fallback: check if worker is inside the module (dev/app/code setup)
            $modulePath = dirname(__DIR__);
            $devPath = $modulePath . '/Worker/worker.php';
            if (is_file($devPath) && is_dir($modulePath . '/Worker/vendor')) {
                return $devPath;
            }

            throw new \RuntimeException(
                'SmartLog worker not found. Run: bin/magento smartlog:install-worker' .
                ' or: cd <module>/Worker && bash install.sh'
            );
        }

        return $path;
    }

    /**
     * Resolve the PHP CLI binary path.
     * PHP_BINARY returns php-fpm when running in web context, which cannot execute scripts.
     */
    private function getPhpCliBinary(): string
    {
        // If PHP_BINARY is already CLI, use it
        $binary = PHP_BINARY ?: 'php';
        if (!str_contains($binary, 'fpm')) {
            return $binary;
        }

        // Try common CLI paths relative to FPM binary
        $dir = dirname($binary);
        foreach ([$dir . '/php', $dir . '/php-cli', '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // Fallback: try 'php' from PATH
        return 'php';
    }
}
