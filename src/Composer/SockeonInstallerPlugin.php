<?php

declare(strict_types=1);

namespace Sockeon\Sockeon\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

final class SockeonInstallerPlugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;

    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        //
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        //
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
        ];
    }

    public function onPostPackageInstall(PackageEvent $event): void
    {
        $operation = $event->getOperation();

        if (!method_exists($operation, 'getPackage')) {
            return;
        }

        $package = $operation->getPackage();

        if ($package->getName() !== 'sockeon/sockeon') {
            return;
        }

        $projectRoot = dirname(
            (string) $this->composer->getConfig()->get('vendor-dir')
        );

        if (!$this->io->isInteractive()) {
            $this->io->write('');
            $this->io->write('<info>⚡ Sockeon installed successfully.</info>');
            $this->io->write('<info>Run setup wizard:</info>');
            $this->io->write('<info>php vendor/bin/sockeon-setup</info>');
            $this->io->write('');

            return;
        }

        (new InstallerSetupWizard())->run(
            $this->io,
            $projectRoot
        );
    }
}
