<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Quissly\Search\Model\Sync\QueueResource;
use Quissly\Search\Model\Sync\SyncWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * quissly:sync {full|run|status} [--website=N] [--batches=N]
 *
 * full   enqueue every eligible product + start progress (the first-sync flow)
 * run    drain queue batches now (same worker the cron uses)
 * status queue depth + progress + gate state
 */
class SyncCommand extends Command
{
    /**
     * @param SyncWorker $worker
     * @param QueueResource $queue
     * @param \Quissly\Search\Model\Sync\FirstSyncGate $gate
     * @param State $appState
     */
    public function __construct(
        private readonly SyncWorker $worker,
        private readonly QueueResource $queue,
        private readonly \Quissly\Search\Model\Sync\FirstSyncGate $gate,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('quissly:sync')
            ->setDescription('Quissly catalog sync: full | run | status')
            ->addArgument('action', InputArgument::REQUIRED, 'full | run | status')
            ->addOption('website', 'w', InputOption::VALUE_REQUIRED, 'Website id', '1')
            ->addOption('batches', 'b', InputOption::VALUE_REQUIRED, 'Batches per run', '10');
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            unset($e); // area already set - expected on repeat invocations
        }
        $websiteId = (int)$input->getOption('website');
        switch ((string)$input->getArgument('action')) {
            case 'full':
                $count = $this->worker->startFullSync($websiteId);
                $output->writeln("<info>Full sync started: $count products enqueued for website $websiteId.</info>");
                $output->writeln('Drain with: bin/magento quissly:sync run  (or let cron do it)');
                return Cli::RETURN_SUCCESS;
            case 'run':
                $result = $this->worker->run($websiteId, max(1, (int)$input->getOption('batches')));
                if (!empty($result['skipped'])) {
                    // Another consumer holds the lock - usually cron, since this
                    // command exists to be run alongside it. Printing the normal
                    // zeros would read as "the queue is empty".
                    $output->writeln(sprintf(
                        '<comment>Another sync is already running for website %d - '
                        . 'nothing done here. Queue pending=%d.</comment>',
                        $websiteId,
                        $result['pending']
                    ));
                    return Cli::RETURN_SUCCESS;
                }
                $output->writeln(sprintf(
                    '<info>sent=%d ok=%d failed=%d pending=%d</info>',
                    $result['sent'],
                    $result['ok'],
                    $result['failed'],
                    $result['pending']
                ));
                return Cli::RETURN_SUCCESS;
            case 'status':
                $pending = $this->queue->countPending($websiteId);
                $progress = $this->worker->progress($websiteId);
                $gate = $this->gate->isOpen($websiteId) ? 'OPEN' : 'CLOSED';
                $output->writeln("Website $websiteId: queue pending=$pending, gate=$gate");
                if ($progress !== null) {
                    // failed is distinct products not delivered, so
                    // ok + failed + pending reconciles against total. The raw
                    // attempt tally is shown only when it differs, because a
                    // retry that later succeeded is worth knowing about but is
                    // not a failure.
                    $output->writeln(sprintf(
                        'Progress: running=%s total=%d ok=%d failed=%d',
                        ($progress['running'] ?? false) ? 'yes' : 'no',
                        $progress['total'] ?? 0,
                        $progress['ok'] ?? 0,
                        $progress['failed'] ?? 0
                    ));
                    $attempts = (int)($progress['attempts_failed'] ?? 0);
                    if ($attempts > (int)($progress['failed'] ?? 0)) {
                        $output->writeln(sprintf(
                            'Retries: %d send attempt(s) failed and were retried successfully',
                            $attempts - (int)($progress['failed'] ?? 0)
                        ));
                    }
                }
                return Cli::RETURN_SUCCESS;
            default:
                $output->writeln('<error>Unknown action. Use: full | run | status</error>');
                return Cli::RETURN_FAILURE;
        }
    }
}
