<?php

declare(strict_types=1);

namespace App\Command\Module;

use Minicli\Command\CommandController;

class ListController extends CommandController
{

    public function usage(): void
    {
        $this->info("");
    }

    public function handle(): void
    {
        global $db, $conf;
        $force = false;
        $this->info('Get list of installed modules');

        require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

        $content = [];
        $content[] = ['id', 'name', 'level', 'external', 'hidden', 'update', 'editor'];
        $modulesdir = dolGetModulesDirs();

        foreach ($modulesdir as $dir) {
            // Load modules attributes in arrays (name, numero, orders) from dir directory
            //print $dir."\n<br>";
            $handle = @opendir($dir);
            if (is_resource($handle)) {
                while (($file = readdir($handle)) !== false) {
                    if (is_readable($dir . $file) && substr($file, 0, 3) == 'mod' && substr($file, dol_strlen($file) - 10) == '.class.php') {
                        require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
                        $modName = substr($file, 0, dol_strlen($file) - 10);

                        include_once $dir . $file; // A class already exists in a different file will send a non catchable fatal error.
                        if (class_exists($modName)) {
                            $objMod = new $modName($db);
                            '@phan-var-force DolibarrModules $objMod';
                            $modNameLoaded[$modName] = $dir;

                            $id = $objMod->numero;
                            $name = $objMod->getName();
                            $publisher = dol_escape_htmltag($objMod->getPublisher());
                            $external = ($objMod->isCoreOrExternalModule() == 'external' ? 'yes' : 'no');
                            $hidden = ($objMod->hidden == 'true' ? 'yes' : 'no');
                            $update = ($objMod->needUpdate == 'true' ? 'yes' : 'no');

                            $content[] = [
                                strval($id),
                                $name,
                                $objMod->version,
                                $external,
                                $hidden,
                                $update,
                                $publisher,
                            ];
                        }
                    }
                }
                closedir($handle);
            }
        }

        if (!empty($modName)) {
            $this->printTable($content);
        } else {
            $this->error("Can't fetch modules list!");
        }
    }
}
