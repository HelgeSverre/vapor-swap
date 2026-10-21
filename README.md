# vapor-swap

Manage named Laravel Vapor profiles and switch accounts for one terminal or permanently. A profile pairs an API token with a team, so you can save multiple teams belonging to the same account under different names.

Requires PHP 8.2+, Composer, and Laravel Vapor CLI. Session switching supports Bash, Zsh, and Fish on macOS/Linux. Profile management and sticky file updates also work without shell integration, including on Windows; PowerShell integration is not included.

## Install from this checkout

```shell
composer install --no-dev
```

To install globally through Composer, register this checkout as a local package, then require it:

```shell
composer global config repositories.vapor-swap path "$PWD"
composer global require 'helgesverre/vapor-swap:@dev'
```

Put Composer's global bin directory on your `PATH` if it is not there already:

```shell
composer global config bin-dir --absolute
```

The package exposes the executable as `vapor-swap`. You can also run `./bin/vapor-swap` directly from this checkout without installing globally.

## Load shell integration

Add the appropriate line to your shell startup file, then run it in your current terminal:

```shell
# ~/.zshrc
eval "$(vapor-swap init zsh)"

# ~/.bashrc (or the file loaded by your login shell)
eval "$(vapor-swap init bash)"

# ~/.config/fish/config.fish
vapor-swap init fish | source
```

When using this checkout directly, substitute the absolute path to `bin/vapor-swap` in the startup line. The generated functions remember that executable's path.

The integration defines `vapor-swap` and `vapor` shell functions. Remove existing aliases with those names before loading it. Run `vapor` through this function to use a session profile; directly running `vendor/bin/vapor` or `command vapor` bypasses the session adapter. Existing unrelated `vapor` shell functions are replaced in the current shell.

## Commands

```shell
vapor-swap add client
# Hidden API token prompt, followed by team selection.

vapor-swap add client --token APITOKENHERE
# Select the only team automatically, or choose from multiple teams.

vapor-swap add client --token APITOKENHERE --team 50346
# Validate the token's access to this team before saving.

vapor-swap add client --token-stdin --team 50346 < /path/to/token-file
# Script-friendly token input; also accepts a pipe.

vapor-swap add client --replace
# Replace an existing profile only after the new credentials validate.

vapor-swap list
vapor-swap ls
vapor-swap list --json

vapor-swap swap client
# Select this profile for this terminal and processes launched from it.

vapor deploy production

vapor-swap swap client --sticky
# Update ~/.laravel-vapor/config.json and clear this terminal's override.

vapor-swap current
vapor-swap current --json

vapor-swap reset
# Clear the session override and use the persistent Vapor config again.

vapor-swap remove client
# Remove the saved profile; existing authentication is left active.

vapor-swap --help
vapor-swap commands
vapor-swap add --help
vapor-swap --version
```

Adding never activates a profile. With no available teams it fails without saving. Multiple teams require a selection in a terminal or an explicit `--team` in a script. Tokens must be supplied with `--token` or `--token-stdin` when input is not a terminal, with `--no-interaction` / `-n`, or in CI. Hidden prompts never fall back to visible token input.

Names are case-sensitive and may contain 1–64 letters, numbers, dots, underscores, and hyphens, starting with a letter or number. Duplicate names require `--replace`. `list` and `current` run offline and never display tokens. `list` marks both session and persistent selections by comparing actual credentials, so changes made outside vapor-swap are reflected. Identical profiles may both be marked.

Success returns zero; failures return nonzero. Status messages and prompts go to stderr; tables, JSON, generated integration, and completions go to stdout. No command changes your startup files automatically.

## Session and persistent behavior

Vapor's current CLI prefers the token in its config file over `VAPOR_API_TOKEN` and reads its team from that file. Exporting a token alone cannot reliably switch an existing installation. Also, an executable cannot change its parent shell's environment.

The shell integration applies exports in your terminal. For a session selection it exports `VAPOR_API_TOKEN`, `VAPOR_SWAP_PROFILE`, and `VAPOR_SWAP_SESSION`. The last variable points to a private session config snapshot. The `vapor` function starts the real Vapor PHP executable with an adapter for `Laravel\VaporCli\Config`, redirecting only its config reads/writes to that snapshot. Your `HOME`, working directory, argument boundaries, and Vapor dependency tree remain unchanged. Vapor operations such as `team:switch` write to the session snapshot while that session is active; saved profiles are not edited.

A session switch leaves the persistent config untouched. Other existing terminals keep their selection. Child shells inherit a session selection; independently opened terminals use the persistent configuration. Sticky switching writes the chosen token and integer team ID into the persistent config, preserving unrelated keys, then unsets this terminal's three override variables. Other existing terminals with overrides keep them. No startup file token export is added or removed.

Without integration, a session swap fails with setup instructions. A sticky swap can update the file, but the executable cannot clear an existing terminal override and reports this limitation.

The adapter is exercised against Vapor CLI 1.70.5. CI also tests the latest available `^1.70` release. It relies on Vapor's current `Config::get`, `set`, and `load` interface; future major changes may require an adapter update. It never edits the installed Vapor package. If your Vapor executable is not on `PATH`, or you want a project-specific installation, set its path explicitly:

```shell
export VAPOR_SWAP_VAPOR_BINARY="$PWD/vendor/bin/vapor"
```

## Files and credentials

Profiles live in `~/.vapor-swap/config.json`:

```json
{
  "version": 1,
  "profiles": {
    "client": {
      "token": "APITOKENHERE",
      "team": 50346,
      "team_name": "Client",
      "account_email": "you@example.com"
    }
  }
}
```

Tokens are stored as plaintext, like Vapor's own configuration. New directories have mode `0700` and written files have mode `0600` on Unix. Writes use a separate lock and atomic replacement. Invalid JSON and unknown profile schema versions are rejected rather than overwritten. Existing directories keep their permissions.

Session snapshots live in `~/.vapor-swap/sessions/`. Resetting, removing a profile, and sticky switching do not delete these snapshots because child shells may still use them. Once all shells using old sessions have exited, their `.json` and `.json.lock` files can be deleted. Removing a profile does not revoke its API token or erase persistent/session copies; revoke tokens in Vapor when needed.

Account discovery follows Vapor CLI: authenticated `GET /api/user`, `/api/owned-teams`, and `/api/teams`, with team results deduplicated by ID and sorted by name. It makes no server-side team switch request, so selecting a profile does not alter the account's remote current-team preference. Sources: [Vapor API client](https://github.com/laravel/vapor-cli/blob/master/src/ConsoleVaporClient.php), [team listing](https://github.com/laravel/vapor-cli/blob/master/src/Commands/TeamListCommand.php), [config reader](https://github.com/laravel/vapor-cli/blob/master/src/Config.php).

## Shell completions

Symfony Console generates completions, including saved profile names for `swap` and `remove`:

```shell
# Bash
vapor-swap completion bash > ~/.vapor-swap/completion.bash
source ~/.vapor-swap/completion.bash

# Zsh: put the generated file in a directory on $fpath, then run compinit.
vapor-swap completion zsh > /path/on/fpath/_vapor-swap

# Fish
vapor-swap completion fish > ~/.config/fish/completions/vapor-swap.fish
```

Ensure the destination directory exists first.

## Development

```shell
composer install
composer test
composer lint
composer format
```

Tests use disposable home directories and fixture credentials. They cover API discovery and malformed responses, transport errors, validation failures, profile replacement, concurrent writes, hidden input, team selection, shell quoting, session isolation, failed sticky writes, reset, and a real Vapor CLI against a local fixture API. Set `TEST_VAPOR_BINARY` to a Vapor PHP executable to select it for the compatibility test; otherwise it searches `PATH`. Tests requiring unavailable shells/PTYs/Vapor are skipped.

CI runs PHP 8.2, 8.3, 8.4, and 8.5 on Linux, plus macOS/PHP 8.4 and Windows/PHP 8.3. PHP 8.1 and 8.6 run as nonblocking compatibility probes: 8.1 is below the current PHP 8.2 dependency baseline, while 8.6 is a prerelease target. Each job checks the actual runtime requirements (independently of Composer's configured platform), lints, runs tests, and uploads a JUnit report. Unix jobs also exercise the real Vapor CLI. Job summaries distinguish dependency, platform, lint, test, and Vapor failures. The workflow can also be started manually.
