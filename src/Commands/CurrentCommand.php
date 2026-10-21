<?php

declare(strict_types=1);

namespace VaporSwap\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VaporSwap\JsonFile;

final class CurrentCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('current')->setDescription('Show the effective Vapor selection and its source')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output selection metadata as JSON, excluding tokens');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $this->paths->session() ? 'session' : 'persistent';
        $config = 'session' === $source ? $this->sessionConfig() : (new JsonFile($this->paths->persistent()))->read();
        if (!array_key_exists('token', $config) && getenv('VAPOR_API_TOKEN')) {
            $source = 'environment';
            $config['token'] = getenv('VAPOR_API_TOKEN');
        }
        $matches = $this->store->matching($config);
        $data = ['source' => $source, 'profiles' => $matches, 'team' => $config['team'] ?? null, 'authenticated' => !empty($config['token'])];
        if ($input->getOption('json')) {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $output->writeln($data['authenticated'] ? 'Current Vapor profile: '.([] === $matches ? '(not saved)' : implode(', ', $matches)).' | team '.($data['team'] ?? '(unset)').' | '.$source : 'No Vapor authentication configured.', OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }
}
