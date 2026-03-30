<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Console\Command;

use Perspective\SmartLog\Composer\WorkerInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InstallWorkerCommand extends Command
{
    public function __construct(?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('smartlog:install-worker')
            ->setDescription('Install SmartLog worker dependencies (LLPhant + OpenSearch) into lib/smartlog/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>SmartLog — Installing worker dependencies</info>');
        $output->writeln('');

        try {
            WorkerInstaller::install();
            $output->writeln('');
            $output->writeln('<info>Worker installed. You can now run: bin/magento smartlog:index</info>');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Installation failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
