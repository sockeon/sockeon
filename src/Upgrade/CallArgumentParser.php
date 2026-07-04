<?php

namespace Sockeon\Sockeon\Upgrade;

/**
 * ponytail: naive comma-split at depth 0; good enough for upgrade codemods on method calls.
 */
final class CallArgumentParser
{
    /**
     * @return list<string>
     */
    public static function split(string $arguments): array
    {
        $args = [];
        $current = '';
        $depth = 0;
        $length = strlen($arguments);

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];
            $prev = $i > 0 ? $arguments[$i - 1] : '';

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                $current .= $char;
                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
                $current .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
                continue;
            }

            if (($char === '"' || $char === "'") && $prev !== '\\') {
                $quote = $char;
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $current .= $arguments[$i];
                    if ($arguments[$i] === $quote && $arguments[$i - 1] !== '\\') {
                        break;
                    }
                    $i++;
                }
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $args[] = trim($current);
        }

        return $args;
    }

    /**
     * @param list<string> $args
     * @param list<int> $order Indexes into $args to emit
     */
    public static function join(array $args, array $order): string
    {
        $parts = [];
        foreach ($order as $index) {
            if (!array_key_exists($index, $args)) {
                return implode(', ', $args);
            }
            $parts[] = $args[$index];
        }

        return implode(', ', $parts);
    }
}
