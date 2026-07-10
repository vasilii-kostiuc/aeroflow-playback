<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Console;

use App\Playback\Application\CompletePlaybackJob;
use App\Playback\Application\FailPlaybackJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Operator stub for the missing aeroflow-agent: reports the active job as finished
 * so the queue advances. The future agent PlaybackCompleted/PlaybackFailed events
 * will replace this command as the real reporter.
 */
#[AsCommand(
    name: 'playback:job:complete',
    description: 'Mark a playback job as completed (or failed) and advance the queue',
)]
final class CompletePlaybackJobCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('jobId', InputArgument::REQUIRED, 'UUID of the playback job')
            ->addOption('fail', null, InputOption::VALUE_NONE, 'Report a failure instead of completion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobId = (string) $input->getArgument('jobId');

        if (!Uuid::isValid($jobId)) {
            $io->error(sprintf('"%s" is not a valid UUID.', $jobId));

            return Command::INVALID;
        }

        $this->messageBus->dispatch(
            $input->getOption('fail') ? new FailPlaybackJob($jobId) : new CompletePlaybackJob($jobId),
        );

        $io->success(sprintf('Playback job %s reported as %s.', $jobId, $input->getOption('fail') ? 'failed' : 'completed'));

        return Command::SUCCESS;
    }
}
