<?php

declare(strict_types=1);

namespace VaporSwap\Commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VaporSwap\JsonFile;

final class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('list')->setAliases(['ls'])->setDescription('List saved profiles and session/persistent selections')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output profile metadata as JSON, excluding tokens')
            ->setHelp(<<<'HELP'
Manage named Vapor token/team profiles:

  vapor-swap add <name> [--token <token> | --token-stdin] [--team <id>] [--replace]
  vapor-swap list [--json]             List profiles (alias: ls)
  vapor-swap swap <name>              Switch this terminal
  vapor-swap swap <name> --sticky     Set the persistent Vapor default
  vapor-swap current [--json]         Show effective authentication
  vapor-swap reset                    Return this terminal to the persistent default
  vapor-swap remove <name>            Remove a saved profile
  vapor-swap init <bash|zsh|fish>      Print shell integration
  vapor-swap completion <shell>       Print shell completions
  vapor-swap commands                 List all commands

Before session switching, load shell integration:
  eval "$(vapor-swap init zsh)"

Use <command> --help for command-specific options.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $session = $this->store->matching($this->sessionConfig());
        $persistent = $this->store->matching((new JsonFile($this->paths->persistent()))->read());
        $rows = [];
        foreach ($this->store->all() as $name => $profile) {
            $name = (string) $name;
            $rows[] = ['name' => (string) $name, 'account_email' => $profile['account_email'], 'team_name' => $profile['team_name'], 'team' => $profile['team'], 'session' => in_array($name, $session, true), 'persistent' => in_array($name, $persistent, true)];
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } elseif ([] === $rows) {
            $this->diagnostic($output, 'No profiles saved. Add one with: vapor-swap add <name>');
        } else {
            (new Table($output))->setHeaders(['Name', 'Account', 'Team', 'ID', 'Session', 'Persistent'])->setRows(array_map(static fn (array $row): array => array_map(static fn ($value): string => \Symfony\Component\Console\Formatter\OutputFormatter::escape((string) $value), [$row['name'], $row['account_email'], $row['team_name'], $row['team'], $row['session'] ? '*' : '', $row['persistent'] ? '*' : '']), $rows))->render();
        }

        return self::SUCCESS;
    }
}
