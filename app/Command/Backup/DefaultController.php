<?php

declare(strict_types=1);

namespace App\Command\Backup;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function usage(): void
    {
        $this->info("All of dolibarr cli for backups
Usage:
 - dolibarr backup --database
 - dolibarr backup --documents
 - dolibarr backup --help");
    }

    public function handle(): void
    {
        global $db, $conf, $dolibarr_main_db_pass, $dolibarr_main_data_root;
        $force = false;
        $this->info('Backups');

        if ($this->hasFlag('help')) {
            $this->usage();
        }

        $dir = sys_get_temp_dir();
        if ($this->hasParam('dir')) {
            $dir = $this->getParam('dir');
        }

        $ladate = date('Ymd');
        if ($this->hasFlag('database')) {
            if ($conf->db->type == "mysqli") {
                //
                $filename = rtrim($dir, '/') . '/' . $ladate . '-database.sql';
                $cmd = "mysqldump " . $conf->db->name . ' -h' . $conf->db->host . ' -p' . $dolibarr_main_db_pass . ' -u' . $conf->db->user . ' -P' . $conf->db->port . ' > ' . $filename;
                $this->info('Database exported ' . $filename);
                exec($cmd);
            } elseif ($conf->db->type == "pgsql") {
            }
        }

        if ($this->hasFlag('documents')) {
            //
            $filename = rtrim($dir, '/') . '/' . $ladate . '-documents.zip';
            $cmd = "zip " . $filename . " --exclude *.log -r .";
            $this->info('Documents exported in ' . $filename);
            $saveCWD = getcwd();
            chdir($dolibarr_main_data_root);
            exec($cmd);
            chdir($saveCWD);
        }
    }
}
