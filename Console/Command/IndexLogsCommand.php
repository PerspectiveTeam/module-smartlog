<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Console\Command;

use Perspective\SmartLog\Model\Config;
use Perspective\SmartLog\Model\Indexer;
use Perspective\SmartLog\Model\LogReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IndexLogsCommand extends Command
{
    private const OPTION_FORCE = 'force';

    public function __construct(
        private readonly Indexer $indexer,
        private readonly LogReader $logReader,
        private readonly Config $config,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('smartlog:index')
            ->setDescription('Index log files for SmartLog semantic search')
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Force indexing even if SmartLog is disabled in config'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled() && !$input->getOption(self::OPTION_FORCE)) {
            $output->writeln('<error>SmartLog is disabled. Enable it in Stores > Configuration > Perspective > SmartLog.</error>');
            $output->writeln('<comment>Use --force to index anyway.</comment>');
            return Command::FAILURE;
        }

        if (!$this->config->getApiKey()) {
            $output->writeln('<error>API key is not configured. Set it in Stores > Configuration > Perspective > SmartLog.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>SmartLog — Log Indexing</info>');
        $output->writeln(sprintf('  Provider : <comment>%s</comment>', $this->config->getProvider()));
        $output->writeln(sprintf('  Model    : <comment>%s</comment>', $this->config->getModel()));
        $output->writeln(sprintf('  Index    : <comment>%s</comment>', $this->config->getOpenSearchIndexName()));
        $output->writeln(sprintf('  Chunk    : <comment>%d entries</comment>', $this->config->getChunkSize()));
        $output->writeln(sprintf('  Batch    : <comment>%d chunks</comment>', $this->config->getBatchSize()));
        $output->writeln('');

        // Count files for progress bar
        $output->write('Scanning log files... ');
        $fileStats = $this->logReader->countLogFiles();
        $output->writeln(sprintf(
            '<comment>%d log files, %d report files</comment>',
            $fileStats['log_files'],
            $fileStats['report_files']
        ));

        $output->writeln('');

        // Setup progress bar
        $progressBar = new ProgressBar($output, $fileStats['total']);
        $progressBar->setFormat(
            " %current%/%max% [%bar%] %percent:3s%% %elapsed:8s%\n"
            . " <info>File:</info> %filename%\n"
            . " <info>Chunks indexed:</info> %chunks%"
        );
        $progressBar->setMessage('starting...', 'filename');
        $progressBar->setMessage('0', 'chunks');
        $progressBar->setBarCharacter('<fg=green>=</>');
        $progressBar->setEmptyBarCharacter('<fg=gray>-</>');
        $progressBar->setProgressCharacter('<fg=green>></>');
        $progressBar->start();

        $startTime = microtime(true);
        $filesProcessed = 0;
        $currentFile = '';

        try {
            $totalIndexed = $this->indexer->reindex(
                progressCallback: function (int $processed) use ($progressBar) {
                    $progressBar->setMessage((string) $processed, 'chunks');
                    $progressBar->display();
                },
                fileCallback: function (string $file) use ($progressBar, &$filesProcessed, &$currentFile) {
                    if ($file !== $currentFile) {
                        if ($currentFile !== '') {
                            $filesProcessed++;
                            $progressBar->setProgress($filesProcessed);
                        }
                        $currentFile = $file;
                        $progressBar->setMessage($file, 'filename');
                        $progressBar->display();
                    }
                }
            );

            // Advance for the last file
            $filesProcessed++;
            $progressBar->setProgress(min($filesProcessed, $fileStats['total']));
            $progressBar->setMessage('done', 'filename');
            $progressBar->setMessage((string) $totalIndexed, 'chunks');
            $progressBar->finish();

            $elapsed = round(microtime(true) - $startTime, 2);
            $output->writeln('');
            $output->writeln('');
            $output->writeln(sprintf(
                '<info>Done! Indexed %d log chunks from %d files in %s seconds.</info>',
                $totalIndexed,
                $filesProcessed,
                $elapsed
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $progressBar->clear();
            $output->writeln('');
            $output->writeln(sprintf('<error>Indexing failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
