<?php

declare(strict_types=1);

namespace App\Command\Thirdparty;

use App\Support\Command;

class ListController extends Command
{
    private const TYPES = [
        'all' => '1 = 1',
        'customer' => 't.client IN (1, 3)',
        'prospect' => 't.client IN (2, 3)',
        'supplier' => 't.fournisseur = 1',
    ];

    public function summary(): string
    {
        return 'List third parties';
    }

    public function usage(): void
    {
        $this->info('List Dolibarr third parties

Usage:
  ./dolibarr thirdparty list [type=all|customer|prospect|supplier] [search=TEXT] [limit=N] [offset=N]

Parameters:
  type=TYPE    - Kind of third party (default: all)
  search=TEXT  - Only names matching TEXT
  limit=N      - At most N results
  offset=N     - Skip the N first results

Columns: id, name, email, customer (yes/prospect/no), supplier.');
    }

    public function handle(): void
    {
        global $db;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        $type = $this->param('type') ?? 'all';
        if ( ! isset(self::TYPES[$type])) {
            $this->fail("Invalid type={$type}: use ".implode(', ', array_keys(self::TYPES)).'.');

            return;
        }
        $limit = (int) ($this->param('limit') ?? 0);
        $offset = (int) ($this->param('offset') ?? 0);
        if ($limit < 0 || $offset < 0) {
            $this->fail('limit and offset must be positive numbers.');

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        $sql = 'SELECT t.rowid, t.nom, t.email, t.client, t.fournisseur FROM '.MAIN_DB_PREFIX.'societe as t';
        $sql .= ' WHERE '.self::TYPES[$type];
        $sql .= ' AND t.entity IN ('.getEntity('societe').')';
        $search = $this->param('search');
        if (null !== $search && '' !== $search) {
            $sql .= natural_search('t.nom', $search);
        }
        $sql .= ' ORDER BY t.nom';
        if ($limit > 0) {
            $sql .= $db->plimit($limit, $offset);
        }

        $resql = $db->query($sql);
        if ( ! $resql) {
            $this->fail("Can't fetch third parties: ".$db->lasterror());

            return;
        }

        $customer = [0 => 'no', 1 => 'yes', 2 => 'prospect', 3 => 'yes'];
        $content = [['id', 'name', 'email', 'customer', 'supplier']];
        while ($obj = $db->fetch_object($resql)) {
            $content[] = [
                (string) $obj->rowid,
                (string) $obj->nom,
                (string) $obj->email,
                $customer[(int) $obj->client] ?? 'no',
                1 === (int) $obj->fournisseur ? 'yes' : 'no',
            ];
        }

        $this->printTable($content);
    }
}
