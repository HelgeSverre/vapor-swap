<?php

declare(strict_types=1);

namespace VaporSwap;

final class Shell
{
    public const VARIABLES = ['VAPOR_API_TOKEN', 'VAPOR_SWAP_SESSION', 'VAPOR_SWAP_PROFILE'];

    public static function validate(string $shell): void
    {
        if (!in_array($shell, ['bash', 'zsh', 'fish'], true)) {
            throw new \InvalidArgumentException('Supported shells: bash, zsh, fish.');
        }
    }

    public static function quote(string $value, string $shell): string
    {
        return 'fish' === $shell
            ? "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'"
            : "'".str_replace("'", "'\"'\"'", $value)."'";
    }

    public static function exports(string $shell, array $variables): string
    {
        self::validate($shell);
        $lines = [];
        foreach ($variables as $name => $value) {
            if (!in_array($name, self::VARIABLES, true)) {
                throw new \LogicException('Unexpected shell variable.');
            }
            $lines[] = 'fish' === $shell
                ? 'set -gx '.$name.' '.self::quote($value, $shell).';'
                : 'export '.$name.'='.self::quote($value, $shell).';';
        }

        return implode("\n", $lines);
    }

    public static function clear(string $shell): string
    {
        self::validate($shell);

        return implode("\n", array_map(static fn (string $name): string => 'fish' === $shell ? 'set -e '.$name.';' : 'unset '.$name.';', self::VARIABLES));
    }

    public static function integration(string $shell, string $binary): string
    {
        self::validate($shell);
        $binary = self::quote($binary, $shell);
        if ('fish' === $shell) {
            return str_replace('__BINARY__', $binary, <<<'FISH'
function vapor-swap --description 'Manage Vapor profiles'
    set -l subcommand ''
    for arg in $argv
        switch $arg
            case --help -h --version -V
                command __BINARY__ $argv
                return $status
        end
        if test -z "$subcommand"; and not string match -q -- '-*' "$arg"
            set subcommand "$arg"
        end
    end
    switch "$subcommand"
        case swap reset
            set -l changes (command __BINARY__ __shell fish $argv)
            set -l result $status
            if test $result -ne 0
                return $result
            end
            printf '%s\n' $changes | source
        case '*'
            command __BINARY__ $argv
    end
end
function vapor --description 'Vapor with the selected session profile'
    if test -n "$VAPOR_SWAP_SESSION"; or test -n "$VAPOR_SWAP_VAPOR_BINARY"
        command __BINARY__ __vapor $argv
    else
        command vapor $argv
    end
end
FISH);
        }

        return str_replace(['__BINARY__', '__SHELL__'], [$binary, $shell], <<<'SH'
vapor-swap() {
    local arg changes subcommand=''
    for arg in "$@"; do
        case "$arg" in
            --help|-h|--version|-V) command __BINARY__ "$@"; return $? ;;
        esac
        case "$arg" in
            -*) ;;
            *) if [ -z "$subcommand" ]; then subcommand="$arg"; fi ;;
        esac
    done
    case "$subcommand" in
        swap|reset)
            changes="$(command __BINARY__ __shell __SHELL__ "$@")" || return $?
            eval "$changes"
            ;;
        *) command __BINARY__ "$@" ;;
    esac
}
vapor() {
    if [ -n "${VAPOR_SWAP_SESSION-}" ] || [ -n "${VAPOR_SWAP_VAPOR_BINARY-}" ]; then
        command __BINARY__ __vapor "$@"
    else
        command vapor "$@"
    fi
}
SH);
    }
}
