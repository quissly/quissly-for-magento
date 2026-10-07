<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Console\Command;

use Magento\Framework\Console\Cli;
use Quissly\Search\Model\Update\Updater;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * quissly:update [--status]
 *
 * The automatic update (Model/Update/Updater), now: checks GitHub for a newer release and
 * installs it, as the daily cron does - a version that failed before is tried again.
 * --status shows what the last check found, without checking.
 */
class UpdateCommand extends Command
{
    /**
     * @param Updater $updater
     */
    public function __construct(private readonly Updater $updater)
    {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('quissly:update')
            ->setDescription('Check GitHub for a newer Quissly release and install it')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show the last check without checking');
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('status')) {
            $output->writeln((string)json_encode($this->updater->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Cli::RETURN_SUCCESS;
        }
        $output->writeln('Checking for a newer Quissly release...');
        $result = $this->updater->run(time(), true);
        $output->writeln($result);
        $failed = strpos($result, 'failed') === 0 || $result === 'check_failed';
        return $failed ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
