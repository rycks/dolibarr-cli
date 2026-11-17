<?php

declare(strict_types=1);

namespace App\Support;

use Minicli\Command\CommandNamespace;
use Minicli\Command\CommandRegistry as MinicliRegistry;
use Minicli\ControllerInterface;

/**
 * Command registry that lists directories with scandir() instead of glob():
 * glob() does not work inside a phar (phar:// stream), scandir() does.
 */
final class CommandRegistry extends MinicliRegistry
{
    public function autoloadNamespaces(string $commandSource): void
    {
        foreach ($this->entries($commandSource) as $entry) {
            if (is_dir($commandSource.'/'.$entry)) {
                $this->registerNamespace($entry, $commandSource);
            }
        }
    }

    public function registerNamespace(string $commandNamespace, string $commandSource): void
    {
        $dir = $commandSource.'/'.$commandNamespace;

        // CommandNamespace::loadControllers() uses glob(): build the controller list here.
        $controllers = [];
        foreach ($this->entries($dir) as $file) {
            if ( ! str_ends_with($file, 'Controller.php')) {
                continue;
            }
            $class = 'App\\Command\\'.$commandNamespace.'\\'.mb_substr($file, 0, -4);
            if ( ! class_exists($class)) {
                continue;
            }
            $controller = new $class();
            if ($controller instanceof ControllerInterface) {
                $controllers[mb_strtolower(mb_substr($file, 0, -mb_strlen('Controller.php')))] = $controller;
            }
        }

        $this->namespaces[mb_strtolower($commandNamespace)] = new CommandNamespace($commandNamespace, $controllers);
    }

    /**
     * @return array<int, string>
     */
    private function entries(string $dir): array
    {
        $entries = @scandir($dir);
        if (false === $entries) {
            return [];
        }

        return array_values(array_filter($entries, static fn (string $e): bool => '.' !== $e[0]));
    }
}
