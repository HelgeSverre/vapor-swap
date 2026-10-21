<?php

declare(strict_types=1);

namespace VaporSwap;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends ConsoleApplication
{
    public function __construct(?string $shell = null, ?Paths $paths = null, ?VaporApi $api = null, ?string $executable = null)
    {
        parent::__construct('vapor-swap', '0.1.0');
        $paths ??= new Paths();
        $store = new ProfileStore(new JsonFile($paths->profiles()));
        $this->add(new Commands\AddCommand($store, $paths, $api ?? new VaporApi()));
        foreach ([Commands\ListCommand::class, Commands\CurrentCommand::class, Commands\SwapCommand::class, Commands\RemoveCommand::class, Commands\ResetCommand::class] as $class) {
            $this->add(new $class($store, $paths, $shell));
        }
        $init = new Command('init');
        $executable ??= dirname(__DIR__).'/bin/vapor-swap';
        $init->setDescription('Print shell integration for Bash, Zsh, or Fish')
            ->addArgument('shell', InputArgument::REQUIRED, 'Shell name: bash, zsh, fish', null, ['bash', 'zsh', 'fish'])
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($executable): int {
                $output->writeln(Shell::integration($input->getArgument('shell'), $executable), OutputInterface::OUTPUT_RAW);

                return Command::SUCCESS;
            });
        $this->add($init);
        $commands = new \Symfony\Component\Console\Command\ListCommand();
        $commands->setName('commands')->setDescription('List all available commands');
        $this->add($commands);
        $this->setDefaultCommand('list');
    }
}
