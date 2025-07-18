<?php

declare(strict_types=1);

namespace App\Command\Thirdparty;

use Minicli\Command\CommandController;
use Minicli\Input;

class ListController extends CommandController
{

    public function usage(): void
    {
        $cmd = implode(" ", $this->getArgs());

        $this->info("Usage:
 - $cmd limit=10 (get only 10 results)
 - $cmd offset=20 (get results after 20 first)
 - $cmd search=xxxx (display only matched thirdparty name)");
    }

    public function handle(): void
    {
        global $db, $conf;
        $force = false;
        $this->info('Get list of dolibarr thirdparties');
        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        $limit = 0;
        if ($this->hasParam('limit')) {
            $limit = $this->getParam('limit');
        }
        $offset = 0;
        if ($this->hasParam('offset')) {
            $offset = $this->getParam('offset');
        }
        $search = '';
        if ($this->hasParam('search')) {
            $search = $this->getParam('search');
        }

        $sql = "SELECT t.rowid";
        $sql .= " FROM " . MAIN_DB_PREFIX . "societe as t";
        $sql .= " WHERE t.client IN (1,2,3)"; // see societe.class.php, 0=no customer, 1=customer, 2=prospect, 3=customer and prospect
        // you can customize that part of SQL for your specific situation
        //$sql .= " AND (code_compta IS NULL OR code_compta LIKE '411%' OR code_compta='')";
        if ($search != '') {
            $sql .= natural_search("t.nom", $search);
        }

        if ($limit > 0) {
            $sql .= $db->plimit($limit, $offset);
        }

        $result = $db->query($sql);
        $content = [];
        $content[] = ['id', 'name', 'email'];
        if ($result) {
            while ($obj = $db->fetch_object($result)) {
                $soc_static = new \Societe($db);
                if ($soc_static->fetch($obj->rowid)) {
                    $content[] = [
                        $soc_static->id,
                        $soc_static->name,
                        $soc_static->email ?? '',
                    ];
                }
            }
            $this->printTable($content);
        } else {
            $this->error("Can't fetch list !");
        }
    }
}
