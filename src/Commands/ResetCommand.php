<?php

declare(strict_types=1);

namespace VaporSwap\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VaporSwap\Shell;

final class ResetCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('reset')->setDescription('Clear this terminal override and use persistent Vapor configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->protocol($output, Shell::clear($this->requireShell()));
        $this->diagnostic($output, 'Session override cleared. Vapor will use its persistent configuration.');

        return self::SUCCESS;
    }
}
