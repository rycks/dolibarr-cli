<?php

/**
 * Source mode: run dolicli from server/src without building the body.
 * Included by the launcher at global scope; never part of the built body.
 */

spl_autoload_register(function ($class) {
    if (0 !== strpos($class, 'Dolicli\\')) {
        return;
    }
    $file = dirname(__FILE__) . '/src/' . str_replace('\\', '/', substr($class, strlen('Dolicli\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require dirname(__FILE__) . '/main.php';
