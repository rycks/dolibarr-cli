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
        dirname(__DIR__).'/app/Command',
    ],

    'theme' => '',

    // Minicli throws on unknown commands only in debug mode: keep it on so the
    // entry point can report them and exit with a non-zero code.
    'debug' => true,
];
