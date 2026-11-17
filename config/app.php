<?php

declare(strict_types=1);

return [
    'app_name' => envconfig(
        'MINICLI_APP_NAME',
        '
     _       _ _      _ _
  __| | ___ | (_) ___| (_)
 / _` |/ _ \| | |/ __| | |
| (_| | (_) | | | (__| | |
 \__,_|\___/|_|_|\___|_|_|

Dolibarr tools from command line'
    ),

    'app_path' => [
        __DIR__.'/../app/Command',
        '@minicli/command-help',
    ],

    'theme' => '',

    // Minicli throws on unknown commands only in debug mode: keep it on so the
    // entry point can report them and exit with a non-zero code.
    'debug' => true,

    // DOLICLI_DEBUG=1 prints the stack trace of unexpected errors.
    'trace' => '1' === envconfig('DOLICLI_DEBUG', '0'),
];
