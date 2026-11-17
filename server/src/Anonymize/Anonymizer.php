<?php

namespace Dolicli\Anonymize;

use Dolicli\Cli\Failure;
use Dolicli\System\Log;

/**
 * Replace the personal data of a Dolibarr database with fake values.
 *
 * Works on MySQL/MariaDB, PostgreSQL and SQLite: each update only sets the
 * columns that exist in the installed version, and any SQL error throws, so
 * the caller can roll the whole transaction back.
 *
 * Fake values only come from the lists below (never from the database being
 * anonymized), and depend on the row id only: they cannot be traced back to
 * the original values.
 */
final class Anonymizer
{
    const FIRSTNAMES = [
        'Jean', 'Marie', 'Pierre', 'Sophie', 'François', 'Isabelle', 'Michel', 'Catherine',
        'Philippe', 'Nathalie', 'Jacques', 'Christine', 'Patrick', 'Sylvie', 'Laurent',
        'Véronique', 'Didier', 'Martine', 'Alain', 'Monique', 'Claude', 'Brigitte',
        'Bernard', 'Dominique', 'André', 'Françoise', 'Daniel', 'Hélène', 'Robert',
        'Anne', 'Nicolas', 'Sandrine', 'Eric', 'Patricia', 'Christian', 'Corinne',
        'Thierry', 'Valérie', 'Gérard', 'Nicole', 'Stéphane', 'Cécile', 'Olivier',
        'Aurélie', 'Julien', 'Émilie', 'Antoine', 'Camille', 'Guillaume', 'Charlotte',
        'Sébastien', 'Mathilde', 'Romain', 'Lucie', 'Thomas', 'Pauline', 'Vincent',
        'Élise', 'Maxime', 'Marguerite', 'Hugo', 'Clara', 'Alexandre', 'Julie',
        'Benoît', 'Marion', 'Raphaël', 'Manon', 'Clément', 'Léa', 'Florian',
        'Chloé', 'Damien', 'Emma', 'Arnaud', 'Inès', 'Quentin', 'Sarah',
        'Xavier', 'Louise', 'Yves', 'Juliette', 'Henri', 'Gabrielle', 'Louis',
    ];

    const LASTNAMES = [
        'Martin', 'Bernard', 'Thomas', 'Petit', 'Robert', 'Richard', 'Durand', 'Dubois',
        'Moreau', 'Laurent', 'Simon', 'Michel', 'Lefebvre', 'Leroy', 'Roux', 'David',
        'Bertrand', 'Morel', 'Fournier', 'Girard', 'Bonnet', 'Dupont', 'Lambert',
        'Fontaine', 'Rousseau', 'Vincent', 'Muller', 'Lefèvre', 'Faure', 'André',
        'Mercier', 'Blanc', 'Guérin', 'Boyer', 'Garnier', 'Chevalier', 'Legrand',
        'Gauthier', 'Garcia', 'Perrin', 'Robin', 'Clément', 'Morin', 'Nicolas',
        'Henry', 'Roussel', 'Mathieu', 'Gautier', 'Masson', 'Marchand', 'Duval',
        'Marty', 'Bénard', 'Renard', 'Berger', 'Charles', 'Guyot', 'Jacquet',
        'Collin', 'Fernandez', 'Léger', 'Bouvier', 'Carlier', 'Poirier', 'Delorme',
    ];

    /** City and zip code at the same index belong together. */
    const CITIES = [
        ['Paris', '75001'], ['Lyon', '69001'], ['Marseille', '13001'], ['Toulouse', '31000'],
        ['Nice', '06000'], ['Nantes', '44000'], ['Strasbourg', '67000'], ['Montpellier', '34000'],
        ['Bordeaux', '33000'], ['Lille', '59000'], ['Rennes', '35000'], ['Reims', '51100'],
        ['Toulon', '83000'], ['Grenoble', '38000'], ['Dijon', '21000'], ['Angers', '49000'],
        ['Nîmes', '30000'], ['Clermont-Ferrand', '63000'], ['Aix-en-Provence', '13100'], ['Brest', '29200'],
        ['Tours', '37000'], ['Amiens', '80000'], ['Limoges', '87000'], ['Perpignan', '66000'],
        ['Metz', '57000'], ['Besançon', '25000'], ['Orléans', '45000'], ['Rouen', '76000'],
        ['Caen', '14000'], ['Nancy', '54000'], ['Saint-Étienne', '42000'], ['Le Havre', '76600'],
        ['Le Mans', '72000'], ['Avignon', '84000'], ['Pau', '64000'], ['La Rochelle', '17000'],
        ['Poitiers', '86000'], ['Colmar', '68000'], ['Chambéry', '73000'], ['Lorient', '56100'],
        ['Valence', '26000'], ['Troyes', '10000'], ['Quimper', '29000'], ['Vannes', '56000'],
        ['Niort', '79000'], ['Tarbes', '65000'], ['Beauvais', '60000'], ['Bourges', '18000'],
        ['Laval', '53000'], ['Belfort', '90000'], ['Épinal', '88000'], ['Blois', '41000'],
    ];

    const STREETS = [
        'Rue de la Paix', 'Avenue des Champs', 'Boulevard Saint-Germain', 'Rue du Commerce',
        'Avenue Victor Hugo', 'Rue de la République', 'Boulevard Haussmann', 'Rue du Faubourg',
        'Avenue Jean Jaures', 'Rue Pasteur', 'Boulevard Voltaire', 'Rue de Rivoli',
        'Avenue de la Liberté', 'Rue Nationale', 'Boulevard Gambetta', 'Rue des Lilas',
        'Avenue du Général de Gaulle', 'Rue de la Gare', 'Boulevard de la Mer', 'Rue des Roses',
        'Rue de la Fontaine', 'Avenue Foch', 'Rue du Moulin', 'Boulevard de Strasbourg',
        'Rue des Écoles', 'Rue du Château', 'Rue des Acacias', 'Avenue de Verdun',
        'Rue de la Mairie', 'Rue du Pont', 'Rue des Vignes', 'Boulevard du Midi',
    ];

    const COMPANY_PREFIXES = [
        'Acme', 'Nexus', 'Vertex', 'Prism', 'Atlas', 'Zenith', 'Apex', 'Nova',
        'Quantum', 'Helix', 'Orion', 'Sigma', 'Vega', 'Titan', 'Echo',
        'Delta', 'Omega', 'Alpha', 'Phoenix', 'Stellar', 'Cobalt', 'Synergie',
        'Horizon', 'Cristal', 'Fusion', 'Émeraude', 'Platine', 'Saphir',
        'Opale', 'Rubis', 'Topaze', 'Jade', 'Onyx', 'Azur', 'Céleste',
    ];

    const COMPANY_SUFFIXES = [
        'Solutions', 'Industries', 'Technologies', 'Services', 'Conseil',
        'Consulting', 'International', 'Group', 'Systems', 'Digital',
        'France', 'Europe', 'Logistique', 'Distribution', 'Ingénierie',
    ];

    /** Constants holding secrets (passwords, API keys, tokens): emptied. */
    const SECRET_CONST_PATTERN = '/(PASS|PASSWORD|_PW|_PW_.*|SECRET|_KEY|APIKEY|API_KEY|TOKEN|_SMTPS_ID.*)$/';

    /** Company identity constants kept as is (codes, not personal data). */
    const KEPT_COMPANY_CONSTS = [
        'MAIN_INFO_SOCIETE_COUNTRY', 'MAIN_INFO_SOCIETE_STATE', 'MAIN_INFO_SOCIETE_FORME_JURIDIQUE',
        'MAIN_INFO_SOCIETE_MONNAIE', 'MAIN_INFO_SOCIETE_FISCAL_MONTH_START',
    ];

    /** Extrafield types that can hold free text. */
    const TEXT_EXTRAFIELD_TYPES = ['varchar', 'text', 'html', 'mail', 'phone', 'url', 'password', 'ip'];

    /** @var \DoliDB */
    private $db;

    /** @var string */
    private $dbType;

    /** @var array<string, array<int, string>> */
    private $columnsCache = [];

    /** @var array<int, string>|null */
    private $tablesCache;

    /**
     * @param \DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->dbType = (string) $db->type;
    }

    // ==================== FAKE VALUES ====================

    /**
     * @param  array<int, mixed>  $list
     * @return mixed
     */
    public static function pick(array $list, int $seed)
    {
        return $list[abs($seed) % count($list)];
    }

    public static function fakeFirstname(int $id): string
    {
        return (string) self::pick(self::FIRSTNAMES, $id);
    }

    public static function fakeLastname(int $id): string
    {
        return (string) self::pick(self::LASTNAMES, $id * 7 + 3);
    }

    public static function fakeCompanyName(int $id): string
    {
        return self::pick(self::COMPANY_PREFIXES, $id) . ' ' . self::pick(self::COMPANY_SUFFIXES, $id * 3 + 1);
    }

    public static function fakeEmail(int $id, string $kind = 'person'): string
    {
        if ('company' === $kind) {
            return 'contact' . $id . '@' . self::ascii((string) self::pick(self::COMPANY_PREFIXES, $id)) . '.example.com';
        }

        return self::fakeLogin($id) . '@example.com';
    }

    public static function fakeLogin(int $id): string
    {
        return self::ascii(self::fakeFirstname($id) . '.' . self::fakeLastname($id)) . $id;
    }

    /**
     * Lowercase ASCII form of a name, for logins and email addresses.
     */
    public static function ascii(string $text): string
    {
        // strtr() before strtolower(): no mbstring needed for the accented letters of the lists.
        $map = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'À' => 'a', 'Â' => 'a', 'Ç' => 'c', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Î' => 'i', 'Ô' => 'o', 'Û' => 'u',
        ];

        return (string) preg_replace('/[^a-z0-9.\-]/', '', strtolower(strtr($text, $map)));
    }

    public static function fakePhone(int $id, bool $mobile = false): string
    {
        $n = abs(crc32(($mobile ? 'mobile' : 'phone') . $id));
        $prefix = $mobile ? (0 === $id % 2 ? 6 : 7) : ($id % 5) + 1;

        return sprintf('0%d %02d %02d %02d %02d', $prefix, $n % 100, ($n >> 8) % 100, ($n >> 16) % 100, ($n >> 24) % 100);
    }

    public static function fakeAddress(int $id): string
    {
        return (($id % 150) + 1) . ' ' . self::pick(self::STREETS, $id * 3);
    }

    public static function fakeTown(int $id): string
    {
        return self::pick(self::CITIES, $id * 11)[0];
    }

    public static function fakeZip(int $id): string
    {
        return self::pick(self::CITIES, $id * 11)[1];
    }

    public static function fakeSiren(int $id): string
    {
        return sprintf('%09d', ($id * 123457) % 1000000000);
    }

    public static function fakeIban(int $id): string
    {
        $n = abs(crc32('iban' . $id));

        return sprintf('FR76%05d%05d%011d%02d', $n % 100000, ($n >> 10) % 100000, abs($id) % 100000000000, abs($id) % 100);
    }

    // ==================== SCHEMA ====================

    /**
     * @return array<int, string> Tables of the Dolibarr prefix, without the prefix
     */
    public function tables(): array
    {
        if (null !== $this->tablesCache) {
            return $this->tablesCache;
        }

        switch ($this->dbType) {
            case 'mysql':
            case 'mysqli':
                $sql = 'SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()';
                break;
            case 'pgsql':
                $sql = 'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema()';
                break;
            case 'sqlite3':
                $sql = "SELECT name FROM sqlite_master WHERE type = 'table'";
                break;
            default:
                throw Failure::operation('DATABASE_NOT_SUPPORTED', "Database type '{$this->dbType}' is not supported for anonymization.");
        }

        $tables = [];
        foreach ($this->select($sql) as $row) {
            $name = self::field($row, 'name');
            if (0 === strpos($name, MAIN_DB_PREFIX)) {
                $tables[] = substr($name, strlen(MAIN_DB_PREFIX));
            }
        }
        sort($tables);

        $this->tablesCache = $tables;

        return $tables;
    }

    public function tableExists(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    /**
     * @return array<int, string>
     */
    public function columns(string $table): array
    {
        if (isset($this->columnsCache[$table])) {
            return $this->columnsCache[$table];
        }
        if (!$this->tableExists($table)) {
            $this->columnsCache[$table] = [];

            return [];
        }

        $name = MAIN_DB_PREFIX . $table;
        if (in_array($this->dbType, ['mysql', 'mysqli'], true)) {
            $sql = "SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $this->db->escape($name) . "'";
        } elseif ('pgsql' === $this->dbType) {
            $sql = "SELECT column_name AS name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '" . $this->db->escape($name) . "'";
        } else {
            $sql = 'PRAGMA table_info(' . preg_replace('/[^a-z0-9_]/i', '', $name) . ')';
        }

        $columns = [];
        foreach ($this->select($sql) as $row) {
            $columns[] = strtolower(self::field($row, 'name'));
        }
        $this->columnsCache[$table] = $columns;

        return $columns;
    }

    public function countRows(string $table, string $where = '1 = 1'): int
    {
        if (! $this->tableExists($table)) {
            return 0;
        }
        $rows = $this->select('SELECT COUNT(*) AS cnt FROM ' . MAIN_DB_PREFIX . $table . ' WHERE ' . $where);

        return isset($rows[0]) ? (int) self::field($rows[0], 'cnt') : 0;
    }

    // ==================== ANONYMIZATION ====================

    public function anonymizeThirdparties(): int
    {
        return $this->perRow('societe', function ($id) {
            return [
            'nom' => self::fakeCompanyName($id),
            'name_alias' => '',
            'address' => self::fakeAddress($id),
            'zip' => self::fakeZip($id),
            'town' => self::fakeTown($id),
            'phone' => self::fakePhone($id),
            'phone_mobile' => '',
            'fax' => '',
            'email' => self::fakeEmail($id, 'company'),
            'url' => '',
            'skype' => '',
            'socialnetworks' => null,
            'siren' => self::fakeSiren($id),
            'siret' => self::fakeSiren($id) . sprintf('%05d', ($id * 31) % 100000),
            'ape' => sprintf('%04d', ($id * 17) % 10000) . chr(65 + ($id % 26)),
            'idprof4' => '', 'idprof5' => '', 'idprof6' => '',
            'tva_intra' => 'FR' . sprintf('%02d', $id % 100) . self::fakeSiren($id),
            'logo' => null,
            'logo_squarred' => null,
            'barcode' => null,
            'ip' => null,
            ];
        });
    }

    public function anonymizeContacts(): int
    {
        return $this->perRow('socpeople', function ($id) {
            return $this->personFields($id) + [
            'phone_perso' => '',
            'fax' => '',
            'birthday' => null,
            'poste' => 'Collaborateur',
            'photo' => null,
            'jabberid' => '',
            'skype' => '',
            'ref_ext' => null,
            ];
        });
    }

    public function anonymizeUsers(string $keepLogin): int
    {
        $where = "login <> '" . $this->db->escape($keepLogin) . "'";

        return $this->perRow('user', function ($id) {
            return $this->personFields($id, 'office_phone', 'user_mobile') + [
            'login' => self::fakeLogin($id),
            'pass' => null,
            'pass_crypted' => null,
            'pass_temp' => null,
            'api_key' => null,
            'personal_mobile' => '',
            'personal_email' => '',
            'office_fax' => '',
            'birth' => null,
            'birth_place' => null,
            'job' => 'Collaborateur',
            'signature' => '',
            'photo' => null,
            'salary' => null,
            'salaryextra' => null,
            'thm' => null,
            'tjm' => null,
            'national_registration_number' => '',
            'ref_employee' => '',
            'idpers1' => '', 'idpers2' => '', 'idpers3' => '',
            'iplastlogin' => '0.0.0.0',
            'ippreviouslogin' => '0.0.0.0',
            ];
        }, $where);
    }

    public function anonymizeEmployment(): int
    {
        return $this->bulk('user_employment', ['salary' => null, 'salaryextra' => null]);
    }

    public function anonymizeMembers(): int
    {
        return $this->perRow('adherent', function ($id) {
            return $this->personFields($id) + [
            'login' => self::fakeLogin($id),
            'pass' => null,
            'pass_crypted' => null,
            'societe' => '',
            'company' => '',
            'phone_perso' => '',
            'url' => '',
            'birth' => null,
            'photo' => null,
            'ip' => '0.0.0.0',
            ];
        });
    }

    public function anonymizeBankData(): int
    {
        $rib = function ($id) {
            return [
            'bank' => 'Banque Anonyme',
            'code_banque' => '00000',
            'code_guichet' => '00000',
            'number' => '00000000000',
            'cle_rib' => '00',
            'bic' => 'BNPAFRPP',
            'iban_prefix' => self::fakeIban($id),
            'domiciliation' => 'Agence Centrale',
            'proprio' => 'Titulaire Anonyme',
            'owner_address' => '',
            'owner_zip' => '',
            'owner_town' => '',
            ];
        };

        $count = $this->perRow('societe_rib', function ($id) use ($rib) {
            return $rib($id) + [
            'last_four' => '0000',
            'cvn' => '',
            'email' => '',
            'stripe_card_ref' => null,
            'stripe_account' => null,
            'card_type' => null,
            'rum' => '',
            'ipaddress' => '0.0.0.0',
            ];
        });
        $count += $this->perRow('user_rib', function ($id) use ($rib) {
            return $rib($id + 2000000);
        });
        $count += $this->perRow('bank_account', function ($id) use ($rib) {
            return $rib($id + 1000000);
        });

        $count += $this->bulk('prelevement_lignes', [
            'client_nom' => 'Titulaire Anonyme',
            'code_banque' => '00000',
            'code_guichet' => '00000',
            'number' => '00000000000',
            'cle_rib' => '00',
        ]);

        // Bank lines: issuer, cheque number, and free labels (Dolibarr's own labels are keys in parentheses).
        $count += $this->bulk('bank', ['emetteur' => '', 'banque' => '', 'num_chq' => '']);
        $this->bulk('bank', ['label' => ['raw' => $this->concat("'Bank line '", 'rowid')]], "label NOT LIKE '(%'");
        $count += $this->bulk('paiement', ['num_paiement' => '']);
        $this->bulk('paiementfourn', ['num_paiement' => '']);

        return $count;
    }

    public function anonymizeExternalAccounts(): int
    {
        return $this->bulk('societe_account', [
            'login' => ['raw' => $this->concat("'user_'", 'rowid')],
            'pass_encoding' => null,
            'pass_crypted' => null,
            'pass_temp' => null,
            'site_account' => '',
            'key_account' => '',
            'ip' => null,
        ]);
    }

    public function anonymizeCommunications(): int
    {
        $count = $this->bulk('actioncomm', [
            'label' => ['raw' => $this->concat("'Event '", 'id')],
            'email_from' => '',
            'email_sender' => '',
            'email_to' => '',
            'email_tocc' => '',
            'email_tobcc' => '',
            'email_subject' => '',
            'email_msgid' => '',
            'errors_to' => '',
            'note' => '',
            'ip' => null,
        ]);
        $count += $this->bulk('ticket', [
            'subject' => ['raw' => $this->concat("'Ticket '", 'rowid')],
            'message' => '',
            'origin_email' => '',
            'origin_references' => '',
            'email_msgid' => '',
            'ip' => null,
        ]);
        $count += $this->bulk('events', ['ip' => '0.0.0.0', 'user_agent' => '', 'description' => '']);

        return $count;
    }

    public function anonymizeMailings(): int
    {
        $count = $this->perRow('mailing_cibles', function ($id) {
            return [
            'lastname' => self::fakeLastname($id),
            'firstname' => self::fakeFirstname($id),
            'email' => self::fakeEmail($id),
            'other' => '',
            ];
        });
        $count += $this->bulk('mailing_unsubscribe', [
            'email' => ['raw' => $this->concat("'unsubscribed_'", 'rowid', "'@example.com'")],
            'ip' => '0.0.0.0',
        ]);
        $count += $this->bulk('mailing', [
            'titre' => ['raw' => $this->concat("'Mailing '", 'rowid')],
            'sujet' => ['raw' => $this->concat("'Mailing '", 'rowid')],
            'body' => '',
            'email_from' => 'mailing@example.com',
            'email_replyto' => '',
            'email_errorsto' => '',
        ]);

        return $count;
    }

    public function anonymizeHr(): int
    {
        $count = $this->perRow('recruitment_recruitmentcandidature', function ($id) {
            return $this->personFields($id) + [
            'date_birth' => null,
            'remuneration_requested' => null,
            'remuneration_proposed' => null,
            'description' => '',
            ];
        });
        $count += $this->perRow('eventorganization_conferenceorboothattendee', function ($id) {
            return [
            'firstname' => self::fakeFirstname($id),
            'lastname' => self::fakeLastname($id),
            'email' => self::fakeEmail($id),
            'email_company' => '',
            'ip' => null,
            ];
        });

        return $count;
    }

    public function anonymizeEmailCollectors(): int
    {
        return $this->bulk('emailcollector_emailcollector', ['login' => '', 'password' => '', 'oauth_service' => null]);
    }

    /**
     * Empty secret constants (passwords, API keys, tokens) and replace the company identity.
     */
    public function anonymizeConstants(): int
    {
        $count = 0;
        foreach ($this->select('SELECT rowid, name, value FROM ' . MAIN_DB_PREFIX . 'const') as $row) {
            $name = (string) $row->name;
            $current = (string) $row->value;
            $value = null;
            if ('MAIN_INFO_SOCIETE_NOM' === $name) {
                $value = 'Société Démo';
            } elseif ('MAIN_INFO_SOCIETE_ADDRESS' === $name) {
                $value = self::fakeAddress((int) $row->rowid);
            } elseif ('MAIN_INFO_SOCIETE_ZIP' === $name) {
                $value = self::fakeZip((int) $row->rowid);
            } elseif ('MAIN_INFO_SOCIETE_TOWN' === $name) {
                $value = self::fakeTown((int) $row->rowid);
            } elseif ('MAIN_INFO_SOCIETE_MAIL' === $name) {
                $value = 'contact@example.com';
            } elseif (
                1 === preg_match('/^MAIN_INFO_(SOCIETE_|SIREN|SIRET|APE|RCS|TVAINTRA|PROFID|CAPITAL)/', $name)
                && ! in_array($name, self::KEPT_COMPANY_CONSTS, true)
            ) {
                $value = '';
            } elseif (
                1 === preg_match(self::SECRET_CONST_PATTERN, $name)
                && 0 !== strpos($name, 'MAIN_SECURITY_')
                && 1 !== preg_match('/^[01]?$/', $current)
            ) {
                // MAIN_SECURITY_* and 0/1 values are settings (MAIN_SECURITY_CSRF_WITH_TOKEN...), not secrets.
                $value = '';
            }

            if (null !== $value) {
                $this->exec('UPDATE ' . MAIN_DB_PREFIX . "const SET value = '" . $this->db->escape($value) . "' WHERE rowid = " . (int) $row->rowid);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Empty the free text extrafields of every object.
     *
     * @return array<string, int> table => rows changed
     */
    public function clearTextExtrafields(): array
    {
        if (! $this->tableExists('extrafields')) {
            return [];
        }

        $byTable = [];
        $types = "'" . implode("', '", self::TEXT_EXTRAFIELD_TYPES) . "'";
        foreach ($this->select('SELECT name, elementtype FROM ' . MAIN_DB_PREFIX . 'extrafields WHERE type IN (' . $types . ')') as $row) {
            $table = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $row->elementtype)) . '_extrafields';
            $byTable[$table][(string) $row->name] = null;
        }

        $results = [];
        foreach ($byTable as $table => $fields) {
            $results[$table] = $this->bulk($table, $fields);
        }

        return $results;
    }

    /**
     * Empty note, note_private and note_public in every table that has them.
     *
     * @return array<string, int> table => rows changed
     */
    public function clearNotes(): array
    {
        $results = [];
        foreach ($this->tables() as $table) {
            $noteColumns = array_values(array_intersect(['note_private', 'note_public'], $this->columns($table)));
            if ([] === $noteColumns) {
                continue;
            }
            $where = implode(' OR ', array_map(function ($c) {
                return "({$c} IS NOT NULL AND {$c} <> '')";
            }, $noteColumns));
            $count = $this->countRows($table, $where);
            if ($count > 0) {
                $this->bulk($table, array_fill_keys($noteColumns, ''), $where);
                $results[$table] = $count;
            }
        }

        return $results;
    }

    public function purgeTable(string $table): int
    {
        $count = $this->countRows($table);
        if ($count > 0) {
            $this->exec('DELETE FROM ' . MAIN_DB_PREFIX . $table);
        }

        return $count;
    }

    /**
     * Rows that would be touched, per category, for the dry run.
     *
     * @param  array<string, bool>  $skip
     * @return array<string, int>
     */
    public function summary(string $keepLogin, array $skip): array
    {
        $summary = [];
        if (empty($skip['thirdparties'])) {
            $summary['thirdparties'] = $this->countRows('societe');
            $summary['contacts'] = $this->countRows('socpeople');
        }
        if (empty($skip['users'])) {
            $summary['users'] = $this->countRows('user', "login <> '" . $this->db->escape($keepLogin) . "'");
            $summary['employment'] = $this->countRows('user_employment');
        }
        if (empty($skip['members'])) {
            $summary['members'] = $this->countRows('adherent');
        }
        if (empty($skip['bank'])) {
            $summary['bank_rib'] = $this->countRows('societe_rib') + $this->countRows('user_rib');
            $summary['bank_accounts'] = $this->countRows('bank_account');
            $summary['bank_lines'] = $this->countRows('bank');
            $summary['external_accounts'] = $this->countRows('societe_account');
        }
        $summary['communications'] = $this->countRows('actioncomm') + $this->countRows('ticket') + $this->countRows('events');
        $summary['mailing_targets'] = $this->countRows('mailing_cibles') + $this->countRows('mailing_unsubscribe');
        $summary['hr'] = $this->countRows('recruitment_recruitmentcandidature') + $this->countRows('eventorganization_conferenceorboothattendee');
        $summary['email_collectors'] = $this->countRows('emailcollector_emailcollector');
        $summary['blocked_log'] = $this->countRows('blockedlog');
        $summary['sessions'] = $this->countRows('session');

        return $summary;
    }

    // ==================== SQL HELPERS ====================

    /**
     * Name, address, phone and email columns shared by people tables.
     *
     * @return array<string, string>
     */
    private function personFields(int $id, string $phoneColumn = 'phone', string $mobileColumn = 'phone_mobile'): array
    {
        return [
            'lastname' => self::fakeLastname($id),
            'firstname' => self::fakeFirstname($id),
            'address' => self::fakeAddress($id),
            'zip' => self::fakeZip($id),
            'town' => self::fakeTown($id),
            $phoneColumn => self::fakePhone($id),
            $mobileColumn => self::fakePhone($id, true),
            'email' => self::fakeEmail($id),
            'socialnetworks' => null,
        ];
    }

    /**
     * Update every row with values computed from its id.
     *
     * @param  callable $values Receives the row id, returns the values
     */
    private function perRow(string $table, callable $values, string $where = '1 = 1'): int
    {
        if (! $this->tableExists($table)) {
            return 0;
        }

        $ids = [];
        foreach ($this->select('SELECT rowid FROM ' . MAIN_DB_PREFIX . $table . ' WHERE ' . $where) as $row) {
            $ids[] = (int) $row->rowid;
        }
        foreach ($ids as $id) {
            $this->update($table, $values($id), 'rowid = ' . $id);
        }

        return count($ids);
    }

    /**
     * Update all rows (matching $where) with the same values. Returns the number of rows matched.
     *
     * @param  array<string, mixed>  $values
     */
    private function bulk(string $table, array $values, string $where = '1 = 1'): int
    {
        if (! $this->tableExists($table)) {
            return 0;
        }
        $count = $this->countRows($table, $where);
        if ($count > 0) {
            $this->update($table, $values, $where);
        }

        return $count;
    }

    /**
     * UPDATE limited to the columns that exist in this Dolibarr version.
     *
     * @param  array<string, mixed>  $values  null => NULL, ['raw' => sql] => SQL expression, else a string literal
     */
    private function update(string $table, array $values, string $where)
    {
        $columns = $this->columns($table);
        $set = [];
        foreach ($values as $column => $value) {
            if (!in_array(strtolower($column), $columns, true)) {
                continue;
            }
            if (null === $value) {
                $set[] = $column . ' = NULL';
            } elseif (is_array($value)) {
                $set[] = $column . ' = ' . $value['raw'];
            } else {
                $set[] = $column . " = '" . $this->db->escape((string) $value) . "'";
            }
        }
        if ([] === $set) {
            return;
        }

        $this->exec('UPDATE ' . MAIN_DB_PREFIX . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . $where);
    }

    private function concat(string ...$parts): string
    {
        return in_array($this->dbType, ['mysql', 'mysqli'], true)
            ? 'CONCAT(' . implode(', ', $parts) . ')'
            : implode(' || ', $parts);
    }

    /**
     * @return array<int, object>
     */
    private function select(string $sql): array
    {
        $resql = $this->exec($sql);
        $rows = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }

        return $rows;
    }

    /**
     * @return mixed Result of the query
     */
    private function exec(string $sql)
    {
        $resql = $this->db->query($sql);
        if (!$resql) {
            $message = 'SQL error: ' . $this->db->lasterror() . ' in: ' . $sql;
            Log::error('anonymize: ' . $message);

            throw Failure::operation('SQL_ERROR', $message);
        }

        return $resql;
    }

    /**
     * Column of a result row, whatever the case the database gives it.
     *
     * @param object $row
     */
    private static function field($row, string $name): string
    {
        $vars = get_object_vars($row);
        if (isset($vars[$name])) {
            return (string) $vars[$name];
        }
        $upper = strtoupper($name);

        return isset($vars[$upper]) ? (string) $vars[$upper] : '';
    }
}
