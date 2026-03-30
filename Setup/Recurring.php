<?php
declare(strict_types=1);
namespace Perspective\SmartLog\Setup;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Perspective\SmartLog\Composer\WorkerInstaller;
use Psr\Log\LoggerInterface;
/**
 * Runs on every bin/magento setup:upgrade.
 *
 * Unlike a DataPatch (which runs only once), Recurring ensures the worker
 * is always present — even after a pipeline deployment that rebuilds the
 * filesystem from scratch (where lib/smartlog/ would be missing).
 */
class Recurring implements InstallSchemaInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        try {
            WorkerInstaller::install();
        } catch (\Throwable $e) {
            // Never block setup:upgrade — log a warning so the admin can act.
            // Manual fallback: bin/magento smartlog:install-worker
            $this->logger->warning(
                'SmartLog: Automatic worker installation failed during setup:upgrade. ' .
                'Run "bin/magento smartlog:install-worker" manually. Error: ' . $e->getMessage()
            );
        }
    }
}
