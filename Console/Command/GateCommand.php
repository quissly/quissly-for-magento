<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Console\Command;

use Magento\Framework\Console\Cli;
use Quissly\Search\Model\Sync\FirstSyncGate;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * quissly:gate {status|open|close} [--website=N]
 *
 * Inspect/control the per-website first-sync gate. The sync worker opens
 * the gate on completion; this command exists for development and support
 * (e.g. verifying interception before sync exists, or closing the gate after
 * rotating to an empty index).
 */
class GateCommand extends Command
{
    /**
     * @param FirstSyncGate $gate
     */
    public function __construct(private readonly FirstSyncGate $gate)
    {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('quissly:gate')
            ->setDescription('Inspect or control the per-website Quissly first-sync gate')
            ->addArgument('action', InputArgument::REQUIRED, 'status | open | close')
            ->addOption('website', 'w', InputOption::VALUE_REQUIRED, 'Website id', '1');
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $websiteId = (int)$input->getOption('website');
        $action = (string)$input->getArgument('action');
        switch ($action) {
            case 'open':
                $this->gate->open($websiteId);
                $output->writeln("<info>Gate OPEN for website $websiteId</info>");
                return Cli::RETURN_SUCCESS;
            case 'close':
                $this->gate->close($websiteId);
                $output->writeln("<info>Gate CLOSED for website $websiteId</info>");
                return Cli::RETURN_SUCCESS;
            case 'status':
                $state = $this->gate->isOpen($websiteId) ? 'OPEN' : 'CLOSED';
                $output->writeln("Website $websiteId gate: $state");
                return Cli::RETURN_SUCCESS;
            default:
                $output->writeln('<error>Unknown action. Use: status | open | close</error>');
                return Cli::RETURN_FAILURE;
        }
    }
}
