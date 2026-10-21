<?php

declare(strict_types=1);

namespace VaporSwap\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VaporSwap\JsonFile;
use VaporSwap\Shell;

final class SwapCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('swap')->setDescription('Activate a profile for this terminal, or persist it with --sticky');
        $this->profileArgument();
        $this->addOption('sticky', null, InputOption::VALUE_NONE, 'Update ~/.laravel-vapor/config.json and clear this terminal override')
            ->setHelp('Session switching requires vapor-swap init shell integration. It leaves the persistent Vapor config untouched. --sticky changes the default used by future sessions; existing terminals with overrides keep their selection.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $profile = $this->store->get($name);
        $selection = ['token' => $profile['token'], 'team' => $profile['team']];
        if ($input->getOption('sticky')) {
            (new JsonFile($this->paths->persistent()))->update(static fn (array $config): array => array_replace($config, $selection));
            if (null !== $this->shell) {
                $this->protocol($output, Shell::clear($this->shell));
            }
            $this->diagnostic($output, 'Persistent Vapor profile: '.$name.' ('.$profile['team_name'].', '.$profile['team'].').');
            if (null === $this->shell && (getenv('VAPOR_API_TOKEN') || $this->paths->session())) {
                $this->diagnostic($output, 'The file is updated, but this executable cannot clear your terminal environment. Load shell integration and run vapor-swap reset.');
            }

            return self::SUCCESS;
        }
        $shell = $this->requireShell();
        $path = $this->paths->newSession();
        $persistent = (new JsonFile($this->paths->persistent()))->read();
        (new JsonFile($path))->update(static fn (): array => array_replace($persistent, $selection));
        $this->protocol($output, Shell::exports($shell, ['VAPOR_API_TOKEN' => $profile['token'], 'VAPOR_SWAP_SESSION' => $path, 'VAPOR_SWAP_PROFILE' => $name]));
        $this->diagnostic($output, 'Session Vapor profile: '.$name.' ('.$profile['team_name'].', '.$profile['team'].').');

        return self::SUCCESS;
    }
}
