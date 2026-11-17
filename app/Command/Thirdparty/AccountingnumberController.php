<?php

declare(strict_types=1);

namespace App\Command\Thirdparty;

use App\Support\Command;
use Societe;

class AccountingnumberController extends Command
{
    public function summary(): string
    {
        return 'Recompute customer accounting codes';
    }

    public function usage(): void
    {
        $this->info('Recompute the customer accounting code of third parties (customers and prospects)
with the numbering rule set in Dolibarr (Setup > Modules > Third parties > Accounting code).

Usage:
  ./dolibarr thirdparty accountingnumber [filters] [--confirm]

Without --confirm, nothing is written: the table shows the codes that would be set.

Filters:
  --only-empty   - Only third parties without accounting code
  prefix=411     - Only third parties whose current code starts with this prefix
  id=12          - Only this third party

Options:
  --confirm      - Write the new codes
  --help         - Display this help message

The command stops at the first error (exit code 1).');
    }

    public function handle(): void
    {
        global $db;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }
        $actingUser = $this->actingUser();
        if (null === $actingUser) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

        $sql = 'SELECT t.rowid FROM '.MAIN_DB_PREFIX.'societe as t';
        // client: 0 = none, 1 = customer, 2 = prospect, 3 = customer and prospect
        $sql .= ' WHERE t.client IN (1, 2, 3)';
        $sql .= ' AND t.entity IN ('.getEntity('societe').')';
        if ($this->hasFlag('only-empty')) {
            $sql .= " AND (t.code_compta IS NULL OR t.code_compta = '')";
        }
        $prefix = $this->param('prefix');
        if (null !== $prefix && '' !== $prefix) {
            $sql .= " AND t.code_compta LIKE '".$db->escape($db->escapeforlike($prefix))."%'";
        }
        $id = $this->param('id');
        if (null !== $id) {
            $sql .= ' AND t.rowid = '.((int) $id);
        }
        $sql .= ' ORDER BY t.rowid';

        $resql = $db->query($sql);
        if ( ! $resql) {
            $this->fail("Can't fetch third parties: ".$db->lasterror());

            return;
        }
        $ids = [];
        while ($obj = $db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
        }

        $confirm = $this->hasFlag('confirm');
        $content = [['id', 'name', 'old code', 'new code']];
        $changed = 0;
        foreach ($ids as $socid) {
            $soc = new Societe($db);
            if ($soc->fetch($socid) <= 0) {
                $this->printTable($content);
                $this->fail("Can't fetch third party id={$socid}: ".$soc->error);

                return;
            }

            $oldcode = (string) $soc->code_compta_client;
            if ($soc->get_codecompta('customer') < 0) {
                $this->printTable($content);
                $this->fail("Can't compute a new accounting code for third party id={$socid} ({$soc->name}): ".$soc->error);

                return;
            }
            $newcode = (string) $soc->code_compta_client;

            if ($confirm && $newcode !== $oldcode) {
                // 4th argument: without it, Societe::update() does not write code_compta at all.
                $result = $soc->update($socid, $actingUser, 0, 1);
                if ($result < 0) {
                    $this->printTable($content);
                    $this->fail("Can't update third party id={$socid} ({$soc->name}): ".implode(', ', array_filter(array_merge([$soc->error], (array) $soc->errors))));

                    return;
                }
            }
            if ($newcode !== $oldcode) {
                $changed++;
            }
            $content[] = [(string) $socid, (string) $soc->name, $oldcode, $newcode];
        }

        $this->printTable($content);
        if ($confirm) {
            $this->success("{$changed} accounting code(s) updated.");
        } else {
            $this->info("Dry run: {$changed} code(s) would change. Add --confirm to write them.");
        }
    }
}
