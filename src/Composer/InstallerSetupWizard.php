<?php

declare(strict_types=1);

namespace Sockeon\Sockeon\Composer;

use Composer\IO\IOInterface;

final class InstallerSetupWizard
{
    public function run(IOInterface $io, string $projectRoot): void
    {
        $io->write('');
        $io->write('<info>⚡ Welcome to Sockeon</info>');
        $io->write('<comment>Realtime PHP Infrastructure Toolkit</comment>');
        $io->write('');

        $this->detectFramework($io, $projectRoot);

        $io->write('');

        $installMcp = $io->askConfirmation(
            'Install MCP config now? [Y/n] ',
            true
        );

        if ($installMcp) {
            $this->writeMcpConfig($projectRoot);
            $io->write('<info>✔ mcp.json created.</info>');
            $io->write('<comment>Install package manually:</comment>');
            $io->write('<comment>composer require sockeon/mcp</comment>');
        }

        $io->write('');

        $ide = $this->askIde($io);

        $this->writeRules($projectRoot, $ide);

        $io->write('');
        $io->write('<info>✔ IDE guidelines generated.</info>');
        $io->write('<info>✔ Sockeon setup complete.</info>');
        $io->write('');
        $io->write('<info>Start building realtime apps with Sockeon.</info>');
        $io->write('');
    }

    private function detectFramework(IOInterface $io, string $root): void
    {
        if (is_file($root . '/artisan')) {
            $io->write('<info>✔ Laravel project detected.</info>');
            return;
        }

        if (is_file($root . '/bin/console')) {
            $io->write('<info>✔ Symfony project detected.</info>');
            return;
        }

        $io->write('<info>✔ Plain PHP project detected.</info>');
    }

    private function askIde(IOInterface $io): string
    {
        $io->write('Choose your IDE');
        $io->write('[1] Cursor');
        $io->write('[2] VS Code');
        $io->write('[3] PhpStorm');
        $io->write('[4] Windsurf');
        $io->write('[5] Zed');
        $io->write('[6] Neovim');

        $choice = trim((string) $io->ask(
            'Enter number [1]: ',
            '1'
        ));

        return match ($choice) {
            '2' => 'vscode',
            '3' => 'phpstorm',
            '4' => 'windsurf',
            '5' => 'zed',
            '6' => 'neovim',
            default => 'cursor',
        };
    }

    private function writeMcpConfig(string $root): void
    {
        $file = $root . '/mcp.json';
        $config = [];

        if (is_file($file)) {
            $decoded = json_decode(
                (string) file_get_contents($file),
                true
            );

            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        if (
            !isset($config['mcpServers']) ||
            !is_array($config['mcpServers'])
        ) {
            $config['mcpServers'] = [];
        }

        $config['mcpServers']['sockeon'] = [
            'command' => 'php',
            'args' => [
                'vendor/sockeon/mcp/public/server.php',
            ],
        ];

        file_put_contents(
            $file,
            json_encode(
                $config,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL
        );
    }

    private function writeRules(string $root, string $ide): void
    {
        $title = match ($ide) {
            'cursor' => 'Cursor',
            'vscode' => 'VS Code',
            'phpstorm' => 'PhpStorm',
            'windsurf' => 'Windsurf',
            'zed' => 'Zed',
            'neovim' => 'Neovim',
            default => 'Editor',
        };

        $rules = <<<MD
# Sockeon Guidelines ({$title})

## Core Architecture
- Organize code by modules.
- Keep controllers thin.
- Move logic into services.

## Realtime Patterns
- Use namespaced events.
- Validate payloads.
- Use explicit room broadcasting.

## Security
- Protect private routes.
- Add rate limiting.
- Use strict CORS.

## Reliability
- Structured logs.
- Graceful disconnect handling.
- Test critical flows.

## Workflow
- Scaffold first.
- Refactor generated code.
- Keep architecture clean.
MD;

        $common = $root . '/.sockeon/sockeon-guidelines.md';

        $this->ensureDirectory($common);
        file_put_contents($common, $rules);

        if ($ide === 'cursor') {
            $cursor = $root . '/.cursor/rules/sockeon-guidelines.mdc';

            $this->ensureDirectory($cursor);
            file_put_contents($cursor, $rules);
        }

        if ($ide === 'vscode') {
            $vscode = $root . '/.github/copilot-instructions.md';

            $this->ensureDirectory($vscode);
            file_put_contents($vscode, $rules);
        }
    }

    private function ensureDirectory(string $file): void
    {
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
