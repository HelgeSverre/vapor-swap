# Vapor Sweep

[![Tests](https://img.shields.io/github/actions/workflow/status/HelgeSverre/vapor-swap/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/HelgeSverre/vapor-swap/actions/workflows/tests.yml)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](composer.json)
[![Shells: Bash, Zsh, Fish](https://img.shields.io/badge/shells-Bash%20%C2%B7%20Zsh%20%C2%B7%20Fish-8B5CF6?style=flat-square&logo=gnubash&logoColor=white)](#shell-setup)
[![License: MIT](https://img.shields.io/github/license/HelgeSverre/vapor-swap?style=flat-square&color=22C55E)](LICENSE)

Save your Laravel Vapor accounts and switch between them. Each saved profile contains an API token and a team.

Requires PHP 8.2+, Composer, and the Vapor CLI. Terminal switching works with Zsh, Bash, and Fish on macOS/Linux.

## Install

```shell
git clone https://github.com/HelgeSverre/vapor-swap.git
cd vapor-swap
composer global config repositories.vapor-swap path "$PWD"
composer global require 'helgesverre/vapor-swap:@dev'
```

Keep the checkout in place: Composer links to it. If `vapor-swap` is not found, add the directory printed by `composer global config bin-dir --absolute` to your `PATH`.

## Shell setup

Add this to `~/.zshrc`:

```shell
eval "$(vapor-swap init zsh)"
```

Then run `source ~/.zshrc` or open a new terminal.

For Bash, add `eval "$(vapor-swap init bash)"` to `~/.bashrc`. For Fish, add `vapor-swap init fish | source` to `~/.config/fish/config.fish`.

## Usage

```shell
vapor-swap add work                  # Prompts for a token and team
vapor-swap add personal --token APITOKENHERE --team 50346
vapor-swap list                      # Show saved profiles
vapor-swap swap work                 # Switch this terminal
vapor deploy production             # Use Vapor as usual
vapor-swap swap personal --sticky    # Set the default for future terminals
vapor-swap current                   # Show the current account and team
vapor-swap reset                     # Return to the saved default
vapor-swap remove work               # Remove a profile
```

`swap` leaves other terminals alone. `--sticky` updates `~/.laravel-vapor/config.json` and clears this terminal's temporary selection. Existing terminals with their own selection keep it.

Use `vapor` after switching; calling `vendor/bin/vapor` directly bypasses the terminal selection.

A token with one team selects it automatically. With multiple teams, you choose from a list. Adding a profile does not switch to it. Use `add work --replace` to update an existing profile.

For scripts, use `--token-stdin` and `--team`. `list` and `current` support `--json`. Run `vapor-swap <command> --help` for all options.

## Zsh completions

```shell
mkdir -p ~/.zsh/completions
vapor-swap completion zsh > ~/.zsh/completions/_vapor-swap
```

Add these lines to `~/.zshrc`, before any existing `compinit` call:

```shell
fpath=("$HOME/.zsh/completions" $fpath)
autoload -Uz compinit && compinit
```

If your Zsh setup already calls `compinit`, keep that call and only add the `fpath` line before it. Open a new terminal to enable completions.

## Stored credentials

Profiles are saved in `~/.vapor-swap/config.json`. Tokens are stored as plaintext with private file permissions, like Vapor's own config. Temporary selections are saved in `~/.vapor-swap/sessions/`; old files can be deleted once the shells using them have closed.

Removing a profile does not revoke its token or clear existing authentication.

## Development

```shell
composer install
composer test
composer lint
```

Tests use disposable home directories and fake credentials. CI tests PHP 8.2–8.5, macOS, Windows, and the real Vapor CLI. PHP 8.1 and 8.6 run as compatibility probes.
