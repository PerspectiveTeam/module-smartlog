<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Composer;

use Composer\Script\Event;

/**
 * Installs the isolated LLPhant worker to <magento-root>/lib/smartlog/.
 *
 * Worker must live outside app/code/ and vendor/ to avoid Magento DI compile
 * scanning its PSR-incompatible dependencies.
 *
 * Runs automatically via composer scripts, or manually:
 *   php -r "require 'vendor/autoload.php'; Perspective\SmartLog\Composer\WorkerInstaller::install();"
 */
class WorkerInstaller
{
    public static function install(?Event $event = null): void
    {
        $moduleWorkerDir = self::findModuleWorkerDir();
        if ($moduleWorkerDir === null) {
            self::log($event, '<warning>SmartLog: Worker source directory not found, skipping.</warning>');
            return;
        }

        $targetDir = self::resolveTargetDir();
        if ($targetDir === null) {
            self::log($event, '<warning>SmartLog: Cannot determine Magento root, skipping worker install.</warning>');
            return;
        }

        // Copy worker source files (not vendor/)
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        foreach (['worker.php', 'composer.json', 'composer.lock', 'install.sh'] as $file) {
            $src = $moduleWorkerDir . '/' . $file;
            if (is_file($src)) {
                copy($src, $targetDir . '/' . $file);
            }
        }

        // Run composer install in target
        $composerBin = self::findComposerBinary();
        self::log($event, '<info>SmartLog: Installing worker dependencies in ' . $targetDir . '</info>');

        $command = sprintf(
            'cd %s && %s install --no-dev --optimize-autoloader --no-interaction 2>&1',
            escapeshellarg($targetDir),
            $composerBin
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            self::log($event, '<error>SmartLog: Worker install failed (exit ' . $exitCode . ')</error>');
            self::log($event, implode("\n", array_slice($output, -10)));
            return;
        }

        self::log($event, '<info>SmartLog: Worker installed successfully.</info>');
    }

    private static function findModuleWorkerDir(): ?string
    {
        // Check relative to this file: Composer/ -> SmartLog/ -> Worker/
        $dir = dirname(__DIR__) . '/Worker';
        if (is_dir($dir) && is_file($dir . '/worker.php')) {
            return $dir;
        }
        return null;
    }

    private static function resolveTargetDir(): ?string
    {
        // Walk up from module dir to find Magento root (has app/ and var/)
        $dir = dirname(__DIR__);
        for ($i = 0; $i < 10; $i++) {
            $dir = dirname($dir);
            if (is_dir($dir . '/app') && (is_dir($dir . '/var') || is_dir($dir . '/pub'))) {
                return $dir . '/lib/smartlog';
            }
        }
        return null;
    }

    private static function findComposerBinary(): string
    {
        foreach (['composer', 'composer.phar', '/usr/local/bin/composer', '/usr/bin/composer'] as $bin) {
            $output = [];
            exec("command -v {$bin} 2>/dev/null", $output, $code);
            if ($code === 0 && !empty($output[0])) {
                return $output[0];
            }
        }
        return 'composer';
    }

    private static function log(?Event $event, string $message): void
    {
        if ($event !== null) {
            $event->getIO()->write($message);
        } else {
            echo strip_tags($message) . PHP_EOL;
        }
    }
}
