<?php

declare(strict_types=1);

namespace OCA\Organization\Command;

use OCA\Organization\Service\ExternalIsolationService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IsolateExternals extends Command
{
    public function __construct(
        private ExternalIsolationService $isolationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('organization:externals:isolate')
            ->setDescription('Apply the instance settings that keep external collaborators inside their projects')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only list what would change');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changes = $input->getOption('check')
            ? $this->isolationService->pendingChanges()
            : $this->isolationService->apply();

        if ($changes === []) {
            $output->writeln('Externals are already isolated.');
            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $output->writeln(($input->getOption('check') ? 'Pending: ' : 'Done: ') . $change);
        }

        return $input->getOption('check') ? self::FAILURE : self::SUCCESS;
    }
}
