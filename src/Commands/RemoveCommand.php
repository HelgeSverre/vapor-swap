<?php

declare(strict_types=1);

namespace VaporSwap\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VaporSwap\JsonFile;

final class RemoveCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('remove')->setDescription('Remove a saved profile without revoking its token');
        $this->profileArgument();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $profile = $this->store->get($name);
        $active = false;
        foreach ([$this->sessionConfig(), (new JsonFile($this->paths->persistent()))->read()] as $config) {
            $active = $active || (($config['token'] ?? null) === $profile['token'] && (int) ($config['team'] ?? 0) === $profile['team']);
        }
        $this->store->remove($name);
        $this->diagnostic($output, 'Removed "'.$name.'".');
        if ($active) {
            $this->diagnostic($output, 'Existing session and persistent authentication remain active. Swap to another profile or reset the session separately.');
        }

        return self::SUCCESS;
    }
}
