<?php

declare(strict_types=1);

namespace Dolifleet\Console\Terminal;

/**
 * Bytes read from the terminal -> names of keys: 'up', 'enter', 'esc', 'ctrl-c', 'shift-tab',
 * 'space', or the character itself ('a', 'é', '/', '?'). Unknown sequences are dropped.
 *
 * A lone ESC is ambiguous (Escape key, or the start of a sequence cut by the read): decode()
 * keeps it pending unless $final is true, which the caller sets after a short silence.
 * Lengths and cuts are in bytes ('8bit'), whatever the characters.
 */
final class Keys
{
    /** @var array<string, string> */
    private const SEQUENCES = [
        "\e[A" => 'up', "\e[B" => 'down', "\e[C" => 'right', "\e[D" => 'left',
        "\eOA" => 'up', "\eOB" => 'down', "\eOC" => 'right', "\eOD" => 'left',
        "\e[H" => 'home', "\e[F" => 'end', "\eOH" => 'home', "\eOF" => 'end',
        "\e[1~" => 'home', "\e[4~" => 'end', "\e[7~" => 'home', "\e[8~" => 'end',
        "\e[2~" => 'insert', "\e[3~" => 'delete', "\e[5~" => 'pgup', "\e[6~" => 'pgdn',
        "\e[Z" => 'shift-tab',
    ];

    /** @var array<string, string> */
    private const CONTROLS = [
        "\r" => 'enter', "\n" => 'enter', "\t" => 'tab', "\x7f" => 'backspace', "\x08" => 'backspace',
        "\x03" => 'ctrl-c', "\x04" => 'ctrl-d', "\x0c" => 'ctrl-l', "\x15" => 'ctrl-u', "\x17" => 'ctrl-w',
        "\x01" => 'ctrl-a', "\x05" => 'ctrl-e', ' ' => 'space',
    ];

    /**
     * @return array{0: list<string>, 1: string} Keys found and bytes left for the next read
     */
    public static function decode(string $bytes, bool $final = false): array
    {
        $keys = [];
        $offset = 0;
        $length = mb_strlen($bytes, '8bit');
        while ($offset < $length) {
            $char = $bytes[$offset];
            if ("\e" === $char) {
                $rest = mb_substr($bytes, $offset, null, '8bit');
                if (1 === mb_strlen($rest, '8bit')) {
                    if ( ! $final) {
                        return [$keys, $rest];
                    }
                    $keys[] = 'esc';
                    ++$offset;
                    continue;
                }
                $matched = self::sequence($rest);
                if (null === $matched) {
                    if ( ! $final && self::incomplete($rest)) {
                        return [$keys, $rest];
                    }
                    // ESC followed by a character: Alt+key, or ESC typed quickly before a key.
                    $keys[] = 'esc';
                    ++$offset;
                    continue;
                }
                [$sequence, $name] = $matched;
                if ('' !== $name) {
                    $keys[] = $name;
                }
                $offset += mb_strlen($sequence, '8bit');
                continue;
            }
            if (isset(self::CONTROLS[$char])) {
                $keys[] = self::CONTROLS[$char];
                ++$offset;
                if ("\r" === $char && $offset < $length && "\n" === $bytes[$offset]) {
                    ++$offset;
                }
                continue;
            }
            $ord = ord($char);
            if ($ord < 0x20) {
                ++$offset;
                continue;
            }
            $size = $ord < 0x80 ? 1 : ($ord >= 0xF0 ? 4 : ($ord >= 0xE0 ? 3 : ($ord >= 0xC0 ? 2 : 1)));
            if ($offset + $size > $length) {
                if ( ! $final) {
                    return [$keys, mb_substr($bytes, $offset, null, '8bit')];
                }
                break;
            }
            $character = mb_substr($bytes, $offset, $size, '8bit');
            $offset += $size;
            if (mb_check_encoding($character, 'UTF-8')) {
                $keys[] = $character;
            }
        }

        return [$keys, ''];
    }

    /**
     * @return array{0: string, 1: string}|null Sequence at the start of $bytes and its key name ('' to drop)
     */
    private static function sequence(string $bytes): ?array
    {
        foreach (self::SEQUENCES as $sequence => $name) {
            if (str_starts_with($bytes, $sequence)) {
                return [$sequence, $name];
            }
        }
        // Any other CSI (modifiers, mouse, focus): parameters then a final byte, dropped.
        if (1 === preg_match('/^\e\[[0-9;?<>]*[\x40-\x7E]/', $bytes, $m)) {
            return [$m[0], ''];
        }
        if (1 === preg_match('/^\eO[\x40-\x7E]/', $bytes, $m)) {
            return [$m[0], ''];
        }

        return null;
    }

    private static function incomplete(string $bytes): bool
    {
        return 1 === preg_match('/^\e(\[[0-9;?<>]*|O)?$/', $bytes);
    }
}
