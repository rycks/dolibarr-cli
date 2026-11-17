<?php

namespace Dolicli\Command\Thirdparty;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Cli\TextRenderer;
use Dolicli\Dolibarr\Dolibarr;
use Societe;

final class AccountingnumberCommand extends Command
{
    public function name(): string
    {
        return 'thirdparty accountingnumber';
    }

    public function summary(): string
    {
        return 'Recompute customer accounting codes';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::flag('only-empty', 'Only third parties without accounting code'),
            Param::value('prefix', 'PREFIX', 'Only third parties whose current code starts with this prefix'),
            Param::value('id', 'ID', 'Only this third party')->integer(),
            Param::flag('confirm', 'Write the new codes (without it, nothing is written)'),
        ];
    }

    public function help(): string
    {
        return "Recompute the customer accounting code of customers and prospects with the numbering rule set in\n"
            . "Dolibarr (Setup > Modules > Third parties > Accounting code). Without --confirm (or with --dry-run),\n"
            . 'nothing is written: the table shows the codes that would be set. Stops at the first error.';
    }

    public function execute(Context $ctx): array
    {
        $db = Dolibarr::db();
        $acting = Dolibarr::actingUser($ctx);
        Dolibarr::requireCore('societe/class/societe.class.php');

        // client: 0 = none, 1 = customer, 2 = prospect, 3 = customer and prospect
        $sql = 'SELECT t.rowid FROM ' . MAIN_DB_PREFIX . 'societe as t WHERE t.client IN (1, 2, 3)';
        $sql .= ' AND t.entity IN (' . getEntity('societe') . ')';
        if ($ctx->flag('only-empty')) {
            $sql .= " AND (t.code_compta IS NULL OR t.code_compta = '')";
        }
        $prefix = (string) $ctx->value('prefix');
        if ('' !== $prefix) {
            $sql .= " AND t.code_compta LIKE '" . $db->escape($db->escapeforlike($prefix)) . "%'";
        }
        if (null !== $ctx->value('id')) {
            $sql .= ' AND t.rowid = ' . ((int) $ctx->value('id'));
        }
        $sql .= ' ORDER BY t.rowid';
        $resql = $db->query($sql);
        if (!$resql) {
            throw Failure::operation('THIRDPARTY_LIST_FAILED', "Can't fetch third parties: " . $db->lasterror());
        }
        $ids = [];
        while ($obj = $db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
        }

        $write = $ctx->flag('confirm') && !$ctx->dryRun();
        $rows = [];
        $changed = 0;
        foreach ($ids as $socid) {
            $soc = new Societe($db);
            if ($soc->fetch($socid) <= 0) {
                throw Failure::operation('THIRDPARTY_FETCH_FAILED', "Can't fetch third party id={$socid}: " . $soc->error, ['done' => $rows]);
            }
            $old = self::customerCode($soc);
            if ($soc->get_codecompta('customer') < 0) {
                throw Failure::operation('ACCOUNTING_CODE_FAILED', "Can't compute a new accounting code for third party id={$socid} ({$soc->name}): " . $soc->error, ['done' => $rows]);
            }
            $new = self::customerCode($soc);
            if ($write && $new !== $old) {
                // 4th argument: without it, Societe::update() does not write code_compta at all.
                if ($soc->update($socid, $acting, 0, 1) < 0) {
                    $errors = implode(', ', array_filter(array_merge([$soc->error], (array) $soc->errors)));
                    throw Failure::operation('THIRDPARTY_UPDATE_FAILED', "Can't update third party id={$socid} ({$soc->name}): {$errors}", ['done' => $rows]);
                }
            }
            if ($new !== $old) {
                $changed++;
            }
            $rows[] = ['id' => $socid, 'name' => (string) $soc->name, 'old_code' => $old, 'new_code' => $new];
        }

        return ['written' => $write, 'changed' => $changed, 'thirdparties' => $rows];
    }

    public function renderText(array $data): string
    {
        $out = TextRenderer::records($data['thirdparties']);

        return $out . ($data['written']
            ? "{$data['changed']} accounting code(s) updated.\n"
            : "Dry run: {$data['changed']} code(s) would change. Add --confirm to write them.\n");
    }

    /**
     * Customer code: code_compta_client from Dolibarr 13, code_compta before.
     */
    private static function customerCode(Societe $soc): string
    {
        // Read through get_object_vars(): the property does not exist in every supported version.
        $properties = get_object_vars($soc);
        if (isset($properties['code_compta_client'])) {
            return (string) $properties['code_compta_client'];
        }

        return (string) $soc->code_compta;
    }
}
