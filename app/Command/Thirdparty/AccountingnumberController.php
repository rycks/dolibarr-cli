<?php

declare(strict_types=1);

namespace App\Command\Thirdparty;

use Minicli\Command\CommandController;
use Minicli\Input;

class AccountingnumberController extends CommandController
{

    public function usage(): void
    {
        $this->info("As this part could be specific for each dolibarr setup please open and update that file :\n"
            . __FILE__ . "  \n");

    }

    public function handle(): void
    {
        global $db, $conf;
        $force = false;
        $this->info('Update accounting number for all dolibarr thirdparties (customer + prospect) according with dolibarr setup rules');

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        require_once DOL_DOCUMENT_ROOT . "/societe/class/societe.class.php";

        $sql = "SELECT t.rowid";
        $sql .= " FROM " . MAIN_DB_PREFIX . "societe as t";
        $sql .= " WHERE t.client IN (1,2,3)"; // see societe.class.php, 0=no customer, 1=customer, 2=prospect, 3=customer and prospect
        // you can customize that part of SQL for your specific situation
        //$sql .= " AND (code_compta IS NULL OR code_compta LIKE '411%' OR code_compta='')";
        $result = $db->query($sql);
        $content = [];
        $content[] = ['name', 'code_compta_old', 'code_compta_new'];
        if ($result) {
            while ($obj = $db->fetch_object($result)) {
                $soc_static = new \Societe($db);
                if ($soc_static->fetch($obj->rowid)) {
                    $oldcode = $soc_static->code_compta_client;
                    $newcode = '';
                    $new = $soc_static->get_codecompta('customer');
                    if ($new < 0) {
                        $this->error("Can't get a new accounting code for that thirdpart id=" . $obj->rowid . ", name=" . $soc_static->name);
                        //exit or not on first error ?
                        exit();
                    } else {
                        $newcode = $soc_static->code_compta_client;
                        $resupdate = $soc_static->update($obj->rowid, '', 0);
                        if ($resupdate < 0) {
                            $this->error("Can't update that thirdpart id=" . $obj->rowid . ", name=" . $soc_static->name . ", error code is=" . $resupdate);
                            //exit or not on first update error ?
                            exit();
                        }
                    }
                    $content[] = [
                        $soc_static->name,
                        $oldcode,
                        $newcode
                    ];
                }
            }
            $this->printTable($content);
        } else {
            $this->error("Can't fetch list !");
        }
    }
}
