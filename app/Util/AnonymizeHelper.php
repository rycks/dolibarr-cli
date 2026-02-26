<?php

declare(strict_types=1);

namespace App\Util;

class AnonymizeHelper
{
    private static array $firstnames = [
        'Jean', 'Marie', 'Pierre', 'Sophie', 'Francois', 'Isabelle', 'Michel', 'Catherine',
        'Philippe', 'Nathalie', 'Jacques', 'Christine', 'Patrick', 'Sylvie', 'Laurent',
        'Veronique', 'Didier', 'Martine', 'Alain', 'Monique', 'Claude', 'Brigitte',
        'Bernard', 'Dominique', 'Andre', 'Francoise', 'Daniel', 'Helene', 'Robert',
        'Anne', 'Nicolas', 'Sandrine', 'Eric', 'Patricia', 'Christian', 'Corinne',
        'Thierry', 'Valerie', 'Gerard', 'Nicole', 'Stephane', 'Cecile', 'Olivier',
        'Aurelie', 'Julien', 'Emilie', 'Antoine', 'Camille', 'Guillaume', 'Charlotte',
        'Sebastien', 'Mathilde', 'Romain', 'Lucie', 'Thomas', 'Pauline', 'Vincent',
        'Elise', 'Maxime', 'Marguerite', 'Hugo', 'Clara', 'Alexandre', 'Julie',
        'Benoit', 'Marion', 'Raphael', 'Manon', 'Clement', 'Lea', 'Florian',
        'Chloe', 'Damien', 'Emma', 'Arnaud', 'Ines', 'Quentin', 'Sarah',
        'Xavier', 'Louise', 'Yves', 'Juliette', 'Henri', 'Gabrielle', 'Louis',
        'Jeanne', 'Marcel', 'Simone', 'Rene', 'Colette', 'Maurice', 'Denise',
        'Paul', 'Odette', 'Georges', 'Madeleine', 'Roger', 'Lucienne', 'Fernand',
        'Germaine', 'Leon', 'Augustine', 'Gaston', 'Marguerite', 'Edmond', 'Yvonne',
    ];

    private static array $lastnames = [
        'Martin', 'Bernard', 'Thomas', 'Petit', 'Robert', 'Richard', 'Durand', 'Dubois',
        'Moreau', 'Laurent', 'Simon', 'Michel', 'Lefebvre', 'Leroy', 'Roux', 'David',
        'Bertrand', 'Morel', 'Fournier', 'Girard', 'Bonnet', 'Dupont', 'Lambert',
        'Fontaine', 'Rousseau', 'Vincent', 'Muller', 'Lefevre', 'Faure', 'Andre',
        'Mercier', 'Blanc', 'Guerin', 'Boyer', 'Garnier', 'Chevalier', 'Legrand',
        'Gauthier', 'Garcia', 'Perrin', 'Robin', 'Clement', 'Morin', 'Nicolas',
        'Henry', 'Roussel', 'Mathieu', 'Gautier', 'Masson', 'Marchand', 'Duval',
        'Denis', 'Dumont', 'Marie', 'Lemaire', 'Noel', 'Meyer', 'Dufour',
        'Meunier', 'Brun', 'Blanchard', 'Giraud', 'Joly', 'Riviere', 'Lucas',
        'Brunet', 'Gaillard', 'Barbier', 'Arnaud', 'Martinez', 'Texier', 'Picard',
        'Collet', 'Moulin', 'Renaud', 'Dumas', 'Hubert', 'Lacroix', 'Guillot',
        'Prevost', 'Bourgeois', 'Pierre', 'Leclercq', 'Perez', 'Leblanc', 'Barre',
        'Marty', 'Benard', 'Renard', 'Berger', 'Charles', 'Guyot', 'Jacquet',
        'Collin', 'Fernandez', 'Leger', 'Bouvier', 'Carlier', 'Poirier', 'Delorme',
    ];

    private static array $cities = [
        'Paris', 'Lyon', 'Marseille', 'Toulouse', 'Nice', 'Nantes', 'Strasbourg',
        'Montpellier', 'Bordeaux', 'Lille', 'Rennes', 'Reims', 'Toulon', 'Grenoble',
        'Dijon', 'Angers', 'Nimes', 'Clermont-Ferrand', 'Aix-en-Provence', 'Brest',
        'Tours', 'Amiens', 'Limoges', 'Perpignan', 'Metz', 'Besancon', 'Orleans',
        'Rouen', 'Caen', 'Nancy', 'Saint-Etienne', 'Le Havre', 'Villeurbanne',
        'Le Mans', 'Avignon', 'Pau', 'La Rochelle', 'Poitiers', 'Calais',
        'Antibes', 'Dunkerque', 'Colmar', 'Chambery', 'Lorient', 'Bayonne',
        'Valence', 'Troyes', 'Quimper', 'Vannes', 'La Roche-sur-Yon', 'Niort',
        'Tarbes', 'Charleville-Mezieres', 'Beauvais', 'Bourges', 'Arras',
        'Laval', 'Belfort', 'Epinal', 'Cherbourg', 'Saint-Brieuc', 'Blois',
    ];

    private static array $zipCodes = [
        '75001', '69001', '13001', '31000', '06000', '44000', '67000',
        '34000', '33000', '59000', '35000', '51100', '83000', '38000',
        '21000', '49000', '30000', '63000', '13100', '29200',
        '37000', '80000', '87000', '66000', '57000', '25000', '45000',
        '76000', '14000', '54000', '42000', '76600', '69100',
        '72000', '84000', '64000', '17000', '86000', '62100',
        '06600', '59140', '68000', '73000', '56100', '64100',
        '26000', '10000', '29000', '56000', '85000', '79000',
        '65000', '08000', '60000', '18000', '62000',
        '53000', '90000', '88000', '50100', '22000', '41000',
    ];

    private static array $streets = [
        'Rue de la Paix', 'Avenue des Champs', 'Boulevard Saint-Germain',
        'Rue du Commerce', 'Avenue Victor Hugo', 'Rue de la Republique',
        'Boulevard Haussmann', 'Rue du Faubourg', 'Avenue Jean Jaures',
        'Rue Pasteur', 'Boulevard Voltaire', 'Rue de Rivoli',
        'Avenue de la Liberte', 'Rue Nationale', 'Boulevard Gambetta',
        'Rue des Lilas', 'Avenue du General de Gaulle', 'Rue de la Gare',
        'Boulevard de la Mer', 'Rue des Roses', 'Rue de la Fontaine',
        'Avenue Foch', 'Rue du Moulin', 'Boulevard de Strasbourg',
        'Rue des Ecoles', 'Avenue Pasteur', 'Rue du Chateau',
        'Boulevard de la Liberte', 'Rue des Acacias', 'Avenue de Verdun',
        'Rue du Marechal Foch', 'Boulevard Victor Hugo', 'Rue de la Mairie',
        'Avenue Gambetta', 'Rue du Pont', 'Boulevard Jean Jaures',
        'Rue de l Eglise', 'Avenue de la Gare', 'Rue des Vignes',
        'Boulevard du Midi', 'Rue du Stade', 'Avenue de la Marne',
    ];

    private static array $companyPrefixes = [
        'Acme', 'Nexus', 'Vertex', 'Prism', 'Atlas', 'Zenith', 'Apex', 'Nova',
        'Quantum', 'Helix', 'Orion', 'Sigma', 'Vega', 'Titan', 'Echo',
        'Delta', 'Omega', 'Alpha', 'Phoenix', 'Stellar', 'Cobalt', 'Synergie',
        'Horizon', 'Cristal', 'Fusion', 'Emeraude', 'Platine', 'Saphir',
        'Opale', 'Rubis', 'Topaze', 'Jade', 'Onyx', 'Azur', 'Celeste',
        'Boreal', 'Austral', 'Equinox', 'Solstice', 'Meridien',
    ];

    private static array $companySuffixes = [
        'Solutions', 'Industries', 'Technologies', 'Services', 'Conseil',
        'Consulting', 'International', 'Group', 'Systems', 'Digital',
        'France', 'Europe', 'Logistique', 'Distribution', 'Engineering',
        'Informatique', 'Telecom', 'Environnement', 'Energie', 'Partners',
    ];

    // Database-sourced data pools (populated by loadFromDatabase)
    private static array $dbFirstnames = [];
    private static array $dbCities = [];
    private static array $dbZipCodes = [];
    private static array $dbAddresses = [];

    private static array $tablesWithNotes = [
        'adherent', 'asset_model', 'asset', 'bank_account', 'bom_bom',
        'bookcal_availabilities', 'bookcal_booking', 'chargesociales',
        'commande_fournisseur', 'commande', 'contrat', 'delivery',
        'deplacement', 'don', 'ecm_directories', 'ecm_files',
        'emailcollector_emailcollector',
        'eventorganization_conferenceorboothattendee',
        'expedition', 'expensereport', 'facture_fourn_rec', 'facture_fourn',
        'facture_rec', 'facture', 'fichinter_rec', 'fichinter', 'holiday',
        'hrm_evaluation', 'hrm_job_user', 'hrm_job', 'hrm_skill',
        'loan_schedule', 'loan', 'mrp_mo', 'partnership', 'payment_loan',
        'product_lot', 'product', 'projet_task', 'projet', 'propal',
        'reception', 'recruitment_recruitmentcandidature',
        'recruitment_recruitmentjobposition', 'resource', 'societe_account',
        'societe', 'socpeople', 'stocktransfer_stocktransfer',
        'supplier_proposal', 'user', 'webhook_target',
        'workstation_workstation',
    ];

    private const MIN_POOL_SIZE = 20;

    // --- Database loading ---

    public static function loadFromDatabase(object $db): void
    {
        $minEntries = self::MIN_POOL_SIZE;

        $values = self::collectDistinctValues($db, 'firstname', ['socpeople', 'user']);
        if (count($values) >= $minEntries) {
            self::$dbFirstnames = $values;
        }

        // Collect city/zip pairs to keep them consistent
        $prefix = MAIN_DB_PREFIX;
        $pairs = [];
        foreach (['societe', 'socpeople', 'user'] as $table) {
            if ( ! self::tableExists($db, $table)) {
                continue;
            }
            $sql = "SELECT DISTINCT town, zip FROM ".$prefix.$table
                ." WHERE town IS NOT NULL AND town != '' AND zip IS NOT NULL AND zip != ''";
            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $key = $obj->town.'|'.$obj->zip;
                    $pairs[$key] = [$obj->town, $obj->zip];
                }
            }
        }
        if (count($pairs) >= $minEntries) {
            $pairs = array_values($pairs);
            sort($pairs);
            self::$dbCities = array_column($pairs, 0);
            self::$dbZipCodes = array_column($pairs, 1);
        }

        $values = self::collectDistinctValues($db, 'address', ['societe', 'socpeople']);
        if (count($values) >= $minEntries) {
            self::$dbAddresses = $values;
        }
    }

    private static function collectDistinctValues(object $db, string $column, array $tables): array
    {
        $prefix = MAIN_DB_PREFIX;
        $parts = [];
        foreach ($tables as $table) {
            if (self::tableExists($db, $table)) {
                $parts[] = "SELECT DISTINCT ".$column." FROM ".$prefix.$table
                    ." WHERE ".$column." IS NOT NULL AND ".$column." != ''";
            }
        }

        if (empty($parts)) {
            return [];
        }

        $sql = implode(' UNION ', $parts)." ORDER BY 1";
        $values = [];
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $values[] = $obj->$column;
            }
        }

        return $values;
    }

    // --- Utility methods ---

    public static function pick(array $list, int $seed): string
    {
        return $list[abs($seed) % count($list)];
    }

    public static function removeAccents(string $str): string
    {
        return strtr($str, [
            "\xC3\xA9" => 'e', "\xC3\xA8" => 'e', "\xC3\xAA" => 'e', "\xC3\xAB" => 'e',
            "\xC3\xA0" => 'a', "\xC3\xA2" => 'a', "\xC3\xA4" => 'a',
            "\xC3\xB9" => 'u', "\xC3\xBB" => 'u', "\xC3\xBC" => 'u',
            "\xC3\xAE" => 'i', "\xC3\xAF" => 'i',
            "\xC3\xB4" => 'o', "\xC3\xB6" => 'o',
            "\xC3\xA7" => 'c',
            "\xC3\x89" => 'E', "\xC3\x88" => 'E', "\xC3\x8A" => 'E', "\xC3\x8B" => 'E',
            "\xC3\x80" => 'A', "\xC3\x82" => 'A', "\xC3\x84" => 'A',
            "\xC3\x99" => 'U', "\xC3\x9B" => 'U', "\xC3\x9C" => 'U',
            "\xC3\x8E" => 'I', "\xC3\x8F" => 'I',
            "\xC3\x94" => 'O', "\xC3\x96" => 'O',
            "\xC3\x87" => 'C',
        ]);
    }

    // --- Generator methods ---

    public static function fakeFirstname(int $id): string
    {
        $pool = ! empty(self::$dbFirstnames) ? self::$dbFirstnames : self::$firstnames;
        return self::pick($pool, $id);
    }

    public static function fakeLastname(int $id): string
    {
        return self::pick(self::$lastnames, $id * 7 + 3);
    }

    public static function fakeCompanyName(int $id): string
    {
        return self::pick(self::$companyPrefixes, $id).' '.self::pick(self::$companySuffixes, $id * 3 + 1);
    }

    public static function fakeEmail(string $firstname, string $lastname, int $id): string
    {
        $f = mb_strtolower(self::removeAccents($firstname));
        $l = mb_strtolower(self::removeAccents($lastname));
        return $f.'.'.$l.$id.'@example.com';
    }

    public static function fakeCompanyEmail(int $id): string
    {
        $name = mb_strtolower(self::pick(self::$companyPrefixes, $id));
        return 'contact'.$id.'@'.$name.'.example.com';
    }

    public static function fakePhone(int $id): string
    {
        $prefix = ($id % 5) + 1;
        $n = abs(crc32('phone'.$id));
        return sprintf(
            '0%d %02d %02d %02d %02d',
            $prefix,
            $n % 100,
            ($n >> 8) % 100,
            ($n >> 16) % 100,
            ($n >> 24) % 100
        );
    }

    public static function fakeMobile(int $id): string
    {
        $prefix = (0 === $id % 2) ? 6 : 7;
        $n = abs(crc32('mobile'.$id));
        return sprintf(
            '0%d %02d %02d %02d %02d',
            $prefix,
            $n % 100,
            ($n >> 8) % 100,
            ($n >> 16) % 100,
            ($n >> 24) % 100
        );
    }

    public static function fakeAddress(int $id): string
    {
        if ( ! empty(self::$dbAddresses)) {
            return self::pick(self::$dbAddresses, $id * 3);
        }
        $number = ($id % 150) + 1;
        return $number.' '.self::pick(self::$streets, $id * 3);
    }

    public static function fakeCity(int $id): string
    {
        $pool = ! empty(self::$dbCities) ? self::$dbCities : self::$cities;
        return self::pick($pool, $id * 11);
    }

    public static function fakeZip(int $id): string
    {
        $pool = ! empty(self::$dbZipCodes) ? self::$dbZipCodes : self::$zipCodes;
        return self::pick($pool, $id * 11);
    }

    public static function fakeSiren(int $id): string
    {
        return sprintf('%09d', ($id * 123457) % 1000000000);
    }

    public static function fakeSiret(int $id): string
    {
        return self::fakeSiren($id).sprintf('%05d', ($id * 31) % 100000);
    }

    public static function fakeTvaIntra(int $id): string
    {
        return 'FR'.sprintf('%02d', $id % 100).self::fakeSiren($id);
    }

    public static function fakeIban(int $id): string
    {
        $n = abs(crc32('iban'.$id));
        return sprintf(
            'FR76%05d%05d%011d%02d',
            $n % 100000,
            ($n >> 10) % 100000,
            abs($id) % 100000000000,
            abs($id) % 100
        );
    }

    public static function fakeApe(int $id): string
    {
        return sprintf('%04d', ($id * 17) % 10000).chr(65 + ($id % 26));
    }

    // --- Database utility methods ---

    public static function countRows(object $db, string $table, string $where = '1=1'): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX.$table." WHERE ".$where;
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            return (int) $obj->cnt;
        }
        return 0;
    }

    public static function tableExists(object $db, string $table): bool
    {
        $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$db->escape(MAIN_DB_PREFIX.$table)."'";
        $resql = $db->query($sql);
        return (bool) ($resql && $db->num_rows($resql) > 0)


        ;
    }

    // --- Anonymization methods ---

    public static function anonymizeThirdparties(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        $sql = "SELECT rowid FROM ".$prefix."societe";
        $resql = $db->query($sql);
        if ( ! $resql) {
            return 0;
        }

        $rows = [];
        while ($obj = $db->fetch_object($resql)) {
            $rows[] = (int) $obj->rowid;
        }

        $count = 0;
        foreach ($rows as $id) {
            $name = $db->escape(self::fakeCompanyName($id));
            $address = $db->escape(self::fakeAddress($id));
            $zip = $db->escape(self::fakeZip($id));
            $city = $db->escape(self::fakeCity($id));
            $phone = $db->escape(self::fakePhone($id));
            $email = $db->escape(self::fakeCompanyEmail($id));
            $siren = $db->escape(self::fakeSiren($id));
            $siret = $db->escape(self::fakeSiret($id));
            $ape = $db->escape(self::fakeApe($id));
            $tva = $db->escape(self::fakeTvaIntra($id));

            $update = "UPDATE ".$prefix."societe SET"
                ." nom = '".$name."'"
                .", name_alias = ''"
                .", address = '".$address."'"
                .", zip = '".$zip."'"
                .", town = '".$city."'"
                .", phone = '".$phone."'"
                .", fax = ''"
                .", email = '".$email."'"
                .", url = ''"
                .", siren = '".$siren."'"
                .", siret = '".$siret."'"
                .", ape = '".$ape."'"
                .", idprof4 = '', idprof5 = '', idprof6 = ''"
                .", tva_intra = '".$tva."'"
                ." WHERE rowid = ".$id;

            if ($db->query($update)) {
                $count++;
            }
        }

        return $count;
    }

    public static function anonymizeContacts(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        $sql = "SELECT rowid FROM ".$prefix."socpeople";
        $resql = $db->query($sql);
        if ( ! $resql) {
            return 0;
        }

        $rows = [];
        while ($obj = $db->fetch_object($resql)) {
            $rows[] = (int) $obj->rowid;
        }

        $count = 0;
        foreach ($rows as $id) {
            $firstname = self::fakeFirstname($id);
            $lastname = self::fakeLastname($id);
            $email = self::fakeEmail($firstname, $lastname, $id);

            $update = "UPDATE ".$prefix."socpeople SET"
                ." lastname = '".$db->escape($lastname)."'"
                .", firstname = '".$db->escape($firstname)."'"
                .", address = '".$db->escape(self::fakeAddress($id))."'"
                .", zip = '".$db->escape(self::fakeZip($id))."'"
                .", town = '".$db->escape(self::fakeCity($id))."'"
                .", phone = '".$db->escape(self::fakePhone($id))."'"
                .", phone_perso = ''"
                .", phone_mobile = '".$db->escape(self::fakeMobile($id))."'"
                .", fax = ''"
                .", email = '".$db->escape($email)."'"
                .", birthday = NULL"
                .", poste = 'Collaborateur'"
                .", socialnetworks = NULL"
                .", photo = NULL"
                ." WHERE rowid = ".$id;

            if ($db->query($update)) {
                $count++;
            }
        }

        return $count;
    }

    public static function anonymizeUsers(object $db, string $keepAdminLogin): int
    {
        $prefix = MAIN_DB_PREFIX;
        $sql = "SELECT rowid FROM ".$prefix."user WHERE login != '".$db->escape($keepAdminLogin)."'";
        $resql = $db->query($sql);
        if ( ! $resql) {
            return 0;
        }

        $rows = [];
        while ($obj = $db->fetch_object($resql)) {
            $rows[] = (int) $obj->rowid;
        }

        $count = 0;
        foreach ($rows as $id) {
            $firstname = self::fakeFirstname($id);
            $lastname = self::fakeLastname($id);
            $email = self::fakeEmail($firstname, $lastname, $id);
            $login = mb_strtolower(self::removeAccents($firstname)).'.'.mb_strtolower(self::removeAccents($lastname)).$id;

            $update = "UPDATE ".$prefix."user SET"
                ." lastname = '".$db->escape($lastname)."'"
                .", firstname = '".$db->escape($firstname)."'"
                .", login = '".$db->escape($login)."'"
                .", address = '".$db->escape(self::fakeAddress($id))."'"
                .", zip = '".$db->escape(self::fakeZip($id))."'"
                .", town = '".$db->escape(self::fakeCity($id))."'"
                .", office_phone = '".$db->escape(self::fakePhone($id))."'"
                .", user_mobile = '".$db->escape(self::fakeMobile($id))."'"
                .", personal_mobile = ''"
                .", email = '".$db->escape($email)."'"
                .", personal_email = ''"
                .", birth = NULL"
                .", birth_place = NULL"
                .", job = 'Collaborateur'"
                .", signature = ''"
                .", socialnetworks = NULL"
                .", salary = NULL, salaryextra = NULL"
                .", national_registration_number = ''"
                .", api_key = NULL"
                .", pass_temp = NULL"
                .", idpers1 = '', idpers2 = '', idpers3 = ''"
                .", iplastlogin = '0.0.0.0'"
                .", ippreviouslogin = '0.0.0.0'"
                ." WHERE rowid = ".$id;

            if ($db->query($update)) {
                $count++;
            }
        }

        return $count;
    }

    public static function anonymizeMembers(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        if ( ! self::tableExists($db, 'adherent')) {
            return 0;
        }

        $sql = "SELECT rowid FROM ".$prefix."adherent";
        $resql = $db->query($sql);
        if ( ! $resql) {
            return 0;
        }

        $rows = [];
        while ($obj = $db->fetch_object($resql)) {
            $rows[] = (int) $obj->rowid;
        }

        $count = 0;
        foreach ($rows as $id) {
            $firstname = self::fakeFirstname($id);
            $lastname = self::fakeLastname($id);
            $email = self::fakeEmail($firstname, $lastname, $id);
            $login = mb_strtolower(self::removeAccents($firstname)).'.'.mb_strtolower(self::removeAccents($lastname)).$id;

            $update = "UPDATE ".$prefix."adherent SET"
                ." lastname = '".$db->escape($lastname)."'"
                .", firstname = '".$db->escape($firstname)."'"
                .", login = '".$db->escape($login)."'"
                .", pass_crypted = ''"
                .", address = '".$db->escape(self::fakeAddress($id))."'"
                .", zip = '".$db->escape(self::fakeZip($id))."'"
                .", town = '".$db->escape(self::fakeCity($id))."'"
                .", phone = '".$db->escape(self::fakePhone($id))."'"
                .", phone_perso = ''"
                .", phone_mobile = '".$db->escape(self::fakeMobile($id))."'"
                .", email = '".$db->escape($email)."'"
                .", url = ''"
                .", birth = NULL"
                .", photo = NULL"
                .", socialnetworks = NULL"
                .", ip = '0.0.0.0'"
                ." WHERE rowid = ".$id;

            if ($db->query($update)) {
                $count++;
            }
        }

        return $count;
    }

    public static function anonymizeBankData(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        $count = 0;

        if (self::tableExists($db, 'societe_rib')) {
            $sql = "SELECT rowid FROM ".$prefix."societe_rib";
            $resql = $db->query($sql);
            if ($resql) {
                $rows = [];
                while ($obj = $db->fetch_object($resql)) {
                    $rows[] = (int) $obj->rowid;
                }
                foreach ($rows as $id) {
                    $iban = $db->escape(self::fakeIban($id));
                    $update = "UPDATE ".$prefix."societe_rib SET"
                        ." bank = 'Banque Anonyme'"
                        .", code_banque = '00000'"
                        .", code_guichet = '00000'"
                        .", number = '00000000000'"
                        .", cle_rib = '00'"
                        .", bic = 'BNPAFRPP'"
                        .", iban_prefix = '".$iban."'"
                        .", domiciliation = 'Agence Centrale'"
                        .", proprio = 'Titulaire Anonyme'"
                        .", owner_address = ''"
                        .", last_four = '0000'"
                        .", cvn = ''"
                        .", email = ''"
                        .", stripe_card_ref = NULL"
                        .", stripe_account = NULL"
                        .", rum = ''"
                        .", ipaddress = '0.0.0.0'"
                        ." WHERE rowid = ".$id;

                    if ($db->query($update)) {
                        $count++;
                    }
                }
            }
        }

        if (self::tableExists($db, 'bank_account')) {
            $sql = "SELECT rowid FROM ".$prefix."bank_account";
            $resql = $db->query($sql);
            if ($resql) {
                $rows = [];
                while ($obj = $db->fetch_object($resql)) {
                    $rows[] = (int) $obj->rowid;
                }
                foreach ($rows as $id) {
                    $iban = $db->escape(self::fakeIban($id + 1000000));
                    $update = "UPDATE ".$prefix."bank_account SET"
                        ." bank = 'Banque Anonyme'"
                        .", code_banque = '00000'"
                        .", code_guichet = '00000'"
                        .", number = '00000000000'"
                        .", cle_rib = '00'"
                        .", bic = 'BNPAFRPP'"
                        .", iban_prefix = '".$iban."'"
                        .", domiciliation = 'Agence Centrale'"
                        .", proprio = 'Titulaire Anonyme'"
                        .", owner_address = ''"
                        .", owner_zip = '75000'"
                        .", owner_town = 'Paris'"
                        ." WHERE rowid = ".$id;

                    if ($db->query($update)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    public static function anonymizeExternalAccounts(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        if ( ! self::tableExists($db, 'societe_account')) {
            return 0;
        }

        $count = self::countRows($db, 'societe_account');
        if ($count > 0) {
            $sql = "UPDATE ".$prefix."societe_account SET"
                ." login = CONCAT('user_', rowid)"
                .", pass_crypted = NULL"
                .", pass_temp = NULL"
                .", site_account = ''"
                .", key_account = ''";
            $db->query($sql);
        }

        return $count;
    }

    public static function anonymizeCommunications(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        if ( ! self::tableExists($db, 'actioncomm')) {
            return 0;
        }

        $count = self::countRows(
            $db,
            'actioncomm',
            "email_from != '' OR email_to != '' OR email_subject != '' OR note != '' OR ip IS NOT NULL"
        );

        if ($count > 0) {
            $sql = "UPDATE ".$prefix."actioncomm SET"
                ." email_from = ''"
                .", email_sender = ''"
                .", email_to = ''"
                .", email_tocc = ''"
                .", email_tobcc = ''"
                .", email_subject = ''"
                .", note = ''"
                .", ip = NULL";
            $db->query($sql);
        }

        return $count;
    }

    public static function anonymizeMailings(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        $count = 0;

        if (self::tableExists($db, 'mailing_cibles')) {
            $sql = "SELECT rowid FROM ".$prefix."mailing_cibles";
            $resql = $db->query($sql);
            if ($resql) {
                $rows = [];
                while ($obj = $db->fetch_object($resql)) {
                    $rows[] = (int) $obj->rowid;
                }
                foreach ($rows as $id) {
                    $firstname = self::fakeFirstname($id);
                    $lastname = self::fakeLastname($id);
                    $email = self::fakeEmail($firstname, $lastname, $id);

                    $update = "UPDATE ".$prefix."mailing_cibles SET"
                        ." lastname = '".$db->escape($lastname)."'"
                        .", firstname = '".$db->escape($firstname)."'"
                        .", email = '".$db->escape($email)."'"
                        .", other = ''"
                        ." WHERE rowid = ".$id;

                    if ($db->query($update)) {
                        $count++;
                    }
                }
            }
        }

        if (self::tableExists($db, 'mailing_unsubscribe')) {
            $unsub = self::countRows($db, 'mailing_unsubscribe');
            if ($unsub > 0) {
                $sql = "UPDATE ".$prefix."mailing_unsubscribe SET"
                    ." email = CONCAT('unsubscribed_', rowid, '@example.com')"
                    .", ip = '0.0.0.0'";
                $db->query($sql);
                $count += $unsub;
            }
        }

        return $count;
    }

    public static function anonymizeEmployment(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        if ( ! self::tableExists($db, 'user_employment')) {
            return 0;
        }

        $count = self::countRows($db, 'user_employment');
        if ($count > 0) {
            $db->query("UPDATE ".$prefix."user_employment SET salary = NULL, salaryextra = NULL");
        }

        return $count;
    }

    public static function anonymizeEmailCollectors(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        if ( ! self::tableExists($db, 'emailcollector_emailcollector')) {
            return 0;
        }

        $count = self::countRows($db, 'emailcollector_emailcollector');
        if ($count > 0) {
            $db->query("UPDATE ".$prefix."emailcollector_emailcollector SET login = '', password = ''");
        }

        return $count;
    }

    public static function clearNotes(object $db): array
    {
        $prefix = MAIN_DB_PREFIX;
        $results = [];

        foreach (self::$tablesWithNotes as $table) {
            $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
                ." WHERE TABLE_SCHEMA = DATABASE()"
                ." AND TABLE_NAME = '".$db->escape($prefix.$table)."'"
                ." AND COLUMN_NAME IN ('note_private', 'note_public')";
            $resql = $db->query($sql);
            if ( ! $resql) {
                continue;
            }

            $columns = [];
            while ($obj = $db->fetch_object($resql)) {
                $columns[] = $obj->COLUMN_NAME;
            }

            if (empty($columns)) {
                continue;
            }

            $whereClauses = [];
            foreach ($columns as $col) {
                $whereClauses[] = "(".$col." IS NOT NULL AND ".$col." != '')";
            }

            $countSql = "SELECT COUNT(*) as cnt FROM ".$prefix.$table
                ." WHERE ".implode(' OR ', $whereClauses);
            $resql = $db->query($countSql);
            $count = 0;
            if ($resql) {
                $obj = $db->fetch_object($resql);
                $count = (int) $obj->cnt;
            }

            if ($count > 0) {
                $setClauses = [];
                foreach ($columns as $col) {
                    $setClauses[] = $col." = ''";
                }
                $db->query("UPDATE ".$prefix.$table." SET ".implode(', ', $setClauses));
            }

            $results[$table] = $count;
        }

        return $results;
    }

    public static function truncateBlockedLog(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        if ( ! self::tableExists($db, 'blockedlog')) {
            return 0;
        }

        $count = self::countRows($db, 'blockedlog');
        if ($count > 0) {
            $db->query("DELETE FROM ".$prefix."blockedlog");
        }

        return $count;
    }

    public static function truncateSessions(object $db): int
    {
        $prefix = MAIN_DB_PREFIX;
        if ( ! self::tableExists($db, 'session')) {
            return 0;
        }

        $count = self::countRows($db, 'session');
        if ($count > 0) {
            $db->query("DELETE FROM ".$prefix."session");
        }

        return $count;
    }

    // --- Dry-run summary ---

    public static function getDryRunSummary(object $db, string $keepAdminLogin): array
    {
        $summary = [];

        $summary['thirdparties'] = self::countRows($db, 'societe');
        $summary['contacts'] = self::countRows($db, 'socpeople');
        $summary['users'] = self::countRows($db, 'user', "login != '".$db->escape($keepAdminLogin)."'");

        if (self::tableExists($db, 'adherent')) {
            $summary['members'] = self::countRows($db, 'adherent');
        }
        if (self::tableExists($db, 'societe_rib')) {
            $summary['bank_rib'] = self::countRows($db, 'societe_rib');
        }
        if (self::tableExists($db, 'bank_account')) {
            $summary['bank_accounts'] = self::countRows($db, 'bank_account');
        }
        if (self::tableExists($db, 'societe_account')) {
            $summary['external_accounts'] = self::countRows($db, 'societe_account');
        }
        if (self::tableExists($db, 'actioncomm')) {
            $summary['communications'] = self::countRows($db, 'actioncomm');
        }
        if (self::tableExists($db, 'mailing_cibles')) {
            $summary['mailing_targets'] = self::countRows($db, 'mailing_cibles');
        }
        if (self::tableExists($db, 'user_employment')) {
            $summary['employment'] = self::countRows($db, 'user_employment');
        }
        if (self::tableExists($db, 'emailcollector_emailcollector')) {
            $summary['email_collectors'] = self::countRows($db, 'emailcollector_emailcollector');
        }
        if (self::tableExists($db, 'blockedlog')) {
            $summary['blocked_log'] = self::countRows($db, 'blockedlog');
        }
        if (self::tableExists($db, 'session')) {
            $summary['sessions'] = self::countRows($db, 'session');
        }

        $noteTables = 0;
        foreach (self::$tablesWithNotes as $table) {
            if (self::tableExists($db, $table)) {
                $noteTables++;
            }
        }
        $summary['note_tables'] = $noteTables;

        return $summary;
    }
}
