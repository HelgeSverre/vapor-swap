<?php

declare(strict_types=1);

namespace VaporSwap\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use VaporSwap\Paths;
use VaporSwap\ProfileStore;
use VaporSwap\VaporApi;

final class AddCommand extends BaseCommand
{
    public function __construct(ProfileStore $store, Paths $paths, private readonly VaporApi $api)
    {
        parent::__construct($store, $paths);
    }

    protected function configure(): void
    {
        $this->setName('add')->setDescription('Validate and save a token/team profile');
        $this->profileArgument();
        $this->addOption('token', null, InputOption::VALUE_REQUIRED, 'Vapor API token (omit for a hidden prompt)')
            ->addOption('token-stdin', null, InputOption::VALUE_NONE, 'Read the token from standard input')
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Team ID; choose interactively when omitted')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Replace an existing profile after validation')
            ->setHelp('Saves a profile without activating it. With one team it is selected automatically. Multiple teams require a selection or --team. Examples: vapor-swap add client; vapor-swap add client --token-stdin --team 50346');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        ProfileStore::validateName($name);
        if (isset($this->store->all()[$name]) && !$input->getOption('replace')) {
            throw new \RuntimeException('Profile "'.$name.'" already exists. Use --replace to update it.');
        }
        $teamId = $input->getOption('team');
        if (null !== $teamId && (!ctype_digit($teamId) || (int) $teamId <= 0 || (string) (int) $teamId !== $teamId)) {
            throw new \InvalidArgumentException('--team must be a positive integer team ID.');
        }
        $token = $input->getOption('token');
        if (null !== $token && $input->getOption('token-stdin')) {
            throw new \InvalidArgumentException('Use either --token or --token-stdin, not both.');
        }
        if ($input->getOption('token-stdin')) {
            $token = rtrim(stream_get_contents(STDIN), "\r\n");
        }
        $interactive = $input->isInteractive() && stream_isatty(STDIN) && 'true' !== getenv('CI') && '1' !== getenv('CI');
        $errorOutput = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if (null === $token) {
            if (!$interactive) {
                throw new \RuntimeException('Provide --token or --token-stdin in noninteractive mode.');
            }
            $question = new Question('Vapor API token: ');
            $question->setHidden(true)->setHiddenFallback(false);
            $token = $this->getHelper('question')->ask($input, $errorOutput, $question);
        }
        if (!is_string($token) || '' === $token || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('The API token must be nonempty and contain no whitespace or control characters.');
        }
        $discovery = $this->api->discover($token);
        $teams = $discovery['teams'];
        if ([] === $teams) {
            throw new \RuntimeException('This token has no available Vapor teams. No profile was saved.');
        }
        if (null !== $teamId) {
            $team = $teams[(int) $teamId] ?? throw new \RuntimeException('This token cannot access team '.$teamId.'. No profile was saved.');
        } elseif (1 === count($teams)) {
            $team = reset($teams);
        } else {
            if (!$interactive) {
                $choices = implode(', ', array_map(static fn (array $team): string => $team['id'].' ('.$team['name'].')', $teams));
                throw new \RuntimeException('Multiple teams are available. Provide --team: '.$choices);
            }
            $choices = array_map(static fn (array $team): string => $team['name'].' ('.$team['id'].')', array_values($teams));
            $question = new ChoiceQuestion('Which team should this profile use?', $choices);
            $question->setMaxAttempts(3);
            $answer = $this->getHelper('question')->ask($input, $errorOutput, $question);
            $team = array_values($teams)[array_search($answer, $choices, true)];
        }
        $this->store->save($name, ['token' => $token, 'team' => $team['id'], 'team_name' => $team['name'], 'account_email' => $discovery['user']['email']], $input->getOption('replace'));
        $this->diagnostic($output, 'Saved "'.$name.'" for '.$team['name'].' ('.$team['id'].'). Activate it with: vapor-swap swap '.$name);

        return self::SUCCESS;
    }
}
