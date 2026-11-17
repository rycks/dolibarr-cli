<?php

declare(strict_types=1);

/**
 * Direct access to the SQLite test database, to check what a command wrote.
 */
function testDb(): PDO
{
    $pdo = new PDO('sqlite:'.DOLICLI_TEST_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

/**
 * @param  array<int|string, mixed>  $params
 */
function dbValue(string $sql, array $params = []): mixed
{
    $stmt = testDb()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function restoreTestDb(): void
{
    if ( ! copy(DOLICLI_TEST_DB_BACKUP, DOLICLI_TEST_DB)) {
        throw new RuntimeException('Cannot restore the SQLite Dolibarr database');
    }
    foreach ((array) scandir(DOLICLI_TEST_DOCUMENTS) as $file) {
        if ( ! is_string($file) || '.' === $file || '..' === $file || in_array($file, $GLOBALS['dolicliInitialDocuments'], true)) {
            continue;
        }
        $path = DOLICLI_TEST_DOCUMENTS.'/'.$file;
        if (is_dir($path) && ! is_link($path)) {
            exec('rm -rf '.escapeshellarg($path));
        } else {
            unlink($path);
        }
    }
}
