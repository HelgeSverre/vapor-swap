<?php

declare(strict_types=1);

namespace VaporSwap\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VaporSwap\JsonFile;
use VaporSwap\Paths;
use VaporSwap\ProfileStore;
use VaporSwap\Shell;

abstract class BaseCommand extends Command
{
    public function __construct(protected readonly ProfileStore $store, protected readonly Paths $paths, protected readonly ?string $shell = null)
    {
        parent::__construct();
    }

    protected function profileArgument(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Saved profile name');
    }

    public function getSubscribedSignals(): array
    {
        return function_exists('pcntl_signal') && defined('SIGINT') ? [SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        return 128 + $signal;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('name')) {
            try {
                $suggestions->suggestValues(array_map(strval(...), array_keys($this->store->all())));
            } catch (\RuntimeException) {
                // Completion must not prevent editing a command when configuration needs repair.
            }
        }
    }

    protected function diagnostic(OutputInterface $output, string $message): void
    {
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $error->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    protected function protocol(OutputInterface $output, string $script): void
    {
        // Shell state changes must still work with --quiet.
        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        $output->writeln($script, OutputInterface::OUTPUT_RAW);
    }

    protected function sessionConfig(): array
    {
        $path = $this->paths->session();
        if (null === $path) {
            return [];
        }
        if (!is_file($path)) {
            throw new \RuntimeException('Session config is missing. Run vapor-swap reset or swap again.');
        }

        return (new JsonFile($path))->read();
    }

    protected function requireShell(): string
    {
        if (null === $this->shell) {
            throw new \RuntimeException('Session switching requires shell integration. Run: eval "$(vapor-swap init zsh)" (Bash: init bash; Fish: vapor-swap init fish | source).');
        }
        Shell::validate($this->shell);

        return $this->shell;
    }
}
