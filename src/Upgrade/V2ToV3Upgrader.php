<?php

namespace Sockeon\Sockeon\Upgrade;

final class V2ToV3Upgrader
{
    /**
     * @var list<string>
     */
    private array $changes = [];

    /**
     * @return array{content: string, changes: list<string>}
     */
    public function upgradePhp(string $content, string $path = ''): array
    {
        $this->changes = [];
        $original = $content;

        $content = $this->protectEngineSendCalls($content);
        $content = $this->applyLiteralRenames($content);
        $content = $this->restoreEngineSendCalls($content);
        $content = $this->reorderMethodArguments($content, 'broadcastTo', [1, 2, 0]);
        $content = $this->reorderMethodArguments($content, 'broadcastExcept', [1, 2, 0]);
        $content = $this->reorderMethodArguments($content, 'broadcastToRoom', [0, 1, 3, 2]);
        $content = $this->rewriteControllerGetClientDataCalls($content);

        if ($content !== $original) {
            $this->changes[] = $path !== '' ? "Updated API calls in {$path}" : 'Updated API calls';
        }

        return ['content' => $content, 'changes' => $this->changes];
    }

    /**
     * @return array{content: string, changes: list<string>}
     */
    public function upgradeConfig(string $content, string $path = ''): array
    {
        $this->changes = [];
        $original = $content;

        if (!$this->looksLikeSockeonConfig($content)) {
            return ['content' => $content, 'changes' => []];
        }

        if (!str_contains($content, "'survivability'") && !str_contains($content, '"survivability"')) {
            $content = $this->insertAfterDebugLine($content, <<<'PHP'

    'engine' => 'stream_select',
    'survivability' => [
        'max_connections' => 10_000,
    ],
PHP
            );
            $this->changes[] = $path !== '' ? "Added engine/survivability to {$path}" : 'Added engine/survivability config';
        }

        if (
            (str_contains($content, "'rate_limit'") || str_contains($content, '"rate_limit"'))
            && !str_contains($content, 'maxGlobalConnections')
        ) {
            $content = $this->appendRateLimitConnectionCaps($content);
            $this->changes[] = $path !== '' ? "Added connection caps to rate_limit in {$path}" : 'Added rate_limit connection caps';
        }

        if ($content === $original && $this->changes === []) {
            return ['content' => $content, 'changes' => []];
        }

        return ['content' => $content, 'changes' => $this->changes];
    }

    /**
     * @return array{changed: bool, changes: list<string>}
     */
    public function upgradeFile(string $path, bool $write): array
    {
        if (!is_file($path)) {
            return ['changed' => false, 'changes' => ["Skip missing file: {$path}"]];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ['changed' => false, 'changes' => ["Could not read: {$path}"]];
        }

        $result = $this->upgradePhp($content, $path);
        $config = $this->upgradeConfig($result['content'], $path);
        $mergedChanges = array_values(array_unique([...$result['changes'], ...$config['changes']]));

        if ($mergedChanges === [] || $config['content'] === $content) {
            return ['changed' => false, 'changes' => []];
        }

        if ($write) {
            file_put_contents($path, $config['content']);
        }

        return ['changed' => true, 'changes' => $mergedChanges];
    }

    /**
     * @return list<array{path: string, changed: bool, changes: list<string>}>
     */
    public function upgradeDirectory(string $directory, bool $write): array
    {
        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getPathname(), '.php')) {
                continue;
            }

            $path = $file->getPathname();
            if ($this->shouldSkipPath($path)) {
                continue;
            }

            $outcome = $this->upgradeFile($path, $write);
            if ($outcome['changed']) {
                $results[] = [
                    'path' => $path,
                    'changed' => true,
                    'changes' => $outcome['changes'],
                ];
            }
        }

        return $results;
    }

    private function looksLikeSockeonConfig(string $content): bool
    {
        return str_contains($content, 'ServerConfig')
            || (str_contains($content, "'host'") && str_contains($content, "'port'"));
    }

    private function shouldSkipPath(string $path): bool
    {
        foreach (['/vendor/', '/.git/', '/node_modules/', '/storage/', '/cache/', '/src/Upgrade/', 'V2ToV3UpgraderTest.php'] as $segment) {
            if (str_contains($path, $segment)) {
                return true;
            }
        }

        return false;
    }

    public function shouldSkip(string $path): bool
    {
        return $this->shouldSkipPath($path);
    }

    private function insertAfterDebugLine(string $content, string $snippet): string
    {
        if (preg_match("/^(\s*)'debug'\s*=>[^\n]*\n/m", $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insertAt = $matches[0][1] + strlen($matches[0][0]);

            return substr($content, 0, $insertAt) . $snippet . "\n" . substr($content, $insertAt);
        }

        if (preg_match('/return\s*\[/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insertAt = $matches[0][1] + strlen($matches[0][0]);

            return substr($content, 0, $insertAt) . $snippet . "\n" . substr($content, $insertAt);
        }

        return $content;
    }

    private function appendRateLimitConnectionCaps(string $content): string
    {
        return (string) preg_replace_callback(
            "/(['\"]rate_limit['\"]\s*=>\s*\[)([^\]]*)\]/s",
            static function (array $matches): string {
                $inner = $matches[2];
                if (str_contains($inner, 'maxGlobalConnections')) {
                    return $matches[0];
                }

                $padding = "\n        'maxGlobalConnections' => 10_000,\n        'maxConnectionsPerIp' => 100,";

                return $matches[1] . $inner . $padding . "\n    ]";
            },
            $content,
            1
        );
    }

    private function applyLiteralRenames(string $content): string
    {
        $map = [
            '/\$server->send\(/' => '$server->emit(',
            '/\$this->getServer\(\)->send\(/' => '$this->getServer()->emit(',
            '/\$server->sendToClient\(/' => '$server->sendRaw(',
            '/\$this->getServer\(\)->sendToClient\(/' => '$this->getServer()->sendRaw(',
            '/\$this->sendToClient\(/' => '$this->sendRaw(',
            '/->broadcastToRoomClients\(/' => '->broadcastToRoom(',
            '/->broadcastToNamespaceClients\(/' => '->broadcastToNamespace(',
            '/->moveClientToNamespace\(/' => '->joinNamespace(',
            '/->getAllClients\(/' => '->getClientIds(',
            '/->broadcastToAll\(/' => '->broadcast(',
            '/\$this->disconnectClient\(/' => '$this->disconnect(',
            '/\$this->isClientConnected\(/' => '$this->isConnected(',
            '/\$this->getClientIpAddress\(/' => '$this->getClientIp(',
            '/\$this->setClientData\(/' => '$this->putData(',
            '/\$this->hasClientData\(/' => '$this->hasData(',
        ];

        foreach ($map as $pattern => $replacement) {
            $content = (string) preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }

    private string $engineSendPlaceholder = '';

    private function protectEngineSendCalls(string $content): string
    {
        $this->engineSendPlaceholder = '__SOCKEON_ENGINE_SEND_' . bin2hex(random_bytes(4)) . '__';

        return (string) preg_replace(
            '/getEngine\(\)->send\(/',
            $this->engineSendPlaceholder . '(',
            $content
        );
    }

    private function restoreEngineSendCalls(string $content): string
    {
        if ($this->engineSendPlaceholder === '') {
            return $content;
        }

        return str_replace($this->engineSendPlaceholder . '(', 'getEngine()->send(', $content);
    }

    /**
     * @param list<int> $order
     */
    private function reorderMethodArguments(string $content, string $method, array $order): string
    {
        $search = '->' . $method . '(';
        $offset = 0;

        while (($pos = strpos($content, $search, $offset)) !== false) {
            $openParen = $pos + strlen($search) - 1;
            $closeParen = $this->findClosingParen($content, $openParen);
            if ($closeParen === null) {
                $offset = $pos + 1;
                continue;
            }

            $argsStart = $openParen + 1;
            $argsString = substr($content, $argsStart, $closeParen - $argsStart);
            $args = CallArgumentParser::split($argsString);
            if (count($args) < count($order)) {
                $offset = $pos + 1;
                continue;
            }

            $newArgs = CallArgumentParser::join($args, $order);
            $content = substr($content, 0, $argsStart) . $newArgs . substr($content, $closeParen);
            $offset = $argsStart + strlen($newArgs) + 1;
        }

        return $content;
    }

    private function rewriteControllerGetClientDataCalls(string $content): string
    {
        $search = '$this->getClientData(';
        $offset = 0;

        while (($pos = strpos($content, $search, $offset)) !== false) {
            $openParen = $pos + strlen($search) - 1;
            $closeParen = $this->findClosingParen($content, $openParen);
            if ($closeParen === null) {
                $offset = $pos + 1;
                continue;
            }

            $argsStart = $openParen + 1;
            $argsString = substr($content, $argsStart, $closeParen - $argsStart);
            $args = CallArgumentParser::split($argsString);

            $replacement = match (count($args)) {
                1 => '$this->allData(' . $args[0] . ')',
                2 => '$this->data(' . implode(', ', $args) . ')',
                default => '$this->getClientData(' . $argsString . ')',
            };

            $content = substr($content, 0, $pos) . $replacement . substr($content, $closeParen + 1);
            $offset = $pos + strlen($replacement);
        }

        return $content;
    }

    private function findClosingParen(string $content, int $openParen): ?int
    {
        $depth = 0;
        $length = strlen($content);

        for ($i = $openParen; $i < $length; $i++) {
            $char = $content[$i];
            $prev = $i > 0 ? $content[$i - 1] : '';

            if (($char === '"' || $char === "'") && $prev !== '\\') {
                $quote = $char;
                $i++;
                while ($i < $length) {
                    if ($content[$i] === $quote && $content[$i - 1] !== '\\') {
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
