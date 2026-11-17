<?php

namespace Dolicli\Command\Thirdparty;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Cli\TextRenderer;
use Dolicli\Dolibarr\Dolibarr;

final class ListCommand extends Command
{
    const TYPES = [
        'all' => '1 = 1',
        'customer' => 't.client IN (1, 3)',
        'prospect' => 't.client IN (2, 3)',
        'supplier' => 't.fournisseur = 1',
    ];

    public function name(): string
    {
        return 'thirdparty list';
    }

    public function summary(): string
    {
        return 'List third parties';
    }

    public function params(): array
    {
        return [
            Param::value('type', 'TYPE', 'Kind of third party (default: all)')->choices(array_keys(self::TYPES)),
            Param::value('search', 'TEXT', 'Only names matching TEXT'),
            Param::value('limit', 'N', 'At most N results')->integer(),
            Param::value('offset', 'N', 'Skip the N first results')->integer(),
        ];
    }

    public function help(): string
    {
        return 'Only the third parties of the current entity are listed (--entity in multicompany).';
    }

    public function execute(Context $ctx): array
    {
        $db = Dolibarr::db();
        $type = (string) ($ctx->value('type') ?: 'all');
        $limit = (int) $ctx->value('limit');
        $offset = (int) $ctx->value('offset');

        $sql = 'SELECT t.rowid, t.nom, t.email, t.client, t.fournisseur FROM ' . MAIN_DB_PREFIX . 'societe as t';
        $sql .= ' WHERE ' . self::TYPES[$type];
        $sql .= ' AND t.entity IN (' . getEntity('societe') . ')';
        $search = (string) $ctx->value('search');
        if ('' !== $search) {
            $sql .= natural_search('t.nom', $search);
        }
        $sql .= ' ORDER BY t.nom';
        if ($limit > 0) {
            $sql .= $db->plimit($limit, $offset);
        }
        $resql = $db->query($sql);
        if (!$resql) {
            throw Failure::operation('THIRDPARTY_LIST_FAILED', "Can't fetch third parties: " . $db->lasterror());
        }
        $customer = [0 => 'no', 1 => 'yes', 2 => 'prospect', 3 => 'yes'];
        $rows = [];
        while ($obj = $db->fetch_object($resql)) {
            $client = (int) $obj->client;
            $rows[] = [
                'id' => (int) $obj->rowid,
                'name' => (string) $obj->nom,
                'email' => (string) $obj->email,
                'customer' => isset($customer[$client]) ? $customer[$client] : 'no',
                'supplier' => 1 === (int) $obj->fournisseur,
            ];
        }

        return ['thirdparties' => $rows, 'count' => count($rows)];
    }

    public function renderText(array $data): string
    {
        return TextRenderer::records($data['thirdparties']);
    }
}
