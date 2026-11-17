<?php

namespace Dolicli\Cli;

/**
 * A command that needs the list of commands (help, manifest).
 */
interface RegistryAware
{
    public function setRegistry(Registry $registry);
}
