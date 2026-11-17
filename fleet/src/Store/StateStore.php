<?php

declare(strict_types=1);

namespace Dolifleet\Store;

use Dolifleet\Paths;
use PDO;

/**
 * Last known states per instance and a short history (~/.local/share/dolifleet/state.sqlite),
 * to show the table without querying the fleet again. Purged automatically after the retention.
 */
final class StateStore
{
    private PDO $pdo;

    public function __construct(string $file = '', private readonly int $retentionDays = 90)
    {
        $file = '' === $file ? Paths::stateDatabase() : $file;
        Paths::ensure(dirname($file));
        $this->pdo = new PDO('sqlite:'.$file);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS states (instance TEXT NOT NULL, received_at INTEGER NOT NULL, generated_at INTEGER NOT NULL, status TEXT NOT NULL, document TEXT NOT NULL)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS states_instance ON states (instance, received_at)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS checks (instance TEXT PRIMARY KEY, checked_at INTEGER NOT NULL, data TEXT NOT NULL)');
        @chmod($file, 0600);
    }

    /**
     * @param array<string, mixed> $document State file of the instance
     */
    public function record(string $instance, array $document): void
    {
        $statement = $this->pdo->prepare('INSERT INTO states (instance, received_at, generated_at, status, document) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([
            $instance,
            time(),
            (int) ($document['generated_at_unix'] ?? 0),
            (string) ($document['status'] ?? 'unknown'),
            json_encode($document, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        $this->pdo->prepare('DELETE FROM states WHERE received_at < ?')->execute([time() - $this->retentionDays * 86400]);
    }

    /**
     * Last state received, with its reception date.
     *
     * @return array{received_at: int, document: array<string, mixed>}|null
     */
    public function last(string $instance): ?array
    {
        $statement = $this->pdo->prepare('SELECT received_at, document FROM states WHERE instance = ? ORDER BY received_at DESC, rowid DESC LIMIT 1');
        $statement->execute([$instance]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ( ! is_array($row)) {
            return null;
        }
        $document = json_decode((string) $row['document'], true);

        return ['received_at' => (int) $row['received_at'], 'document' => is_array($document) ? $document : []];
    }

    /**
     * @return list<array{received_at: int, generated_at: int, status: string}>
     */
    public function history(string $instance, int $limit = 50): array
    {
        $statement = $this->pdo->prepare('SELECT received_at, generated_at, status FROM states WHERE instance = ? ORDER BY received_at DESC LIMIT '.max(1, $limit));
        $statement->execute([$instance]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['received_at' => (int) $row['received_at'], 'generated_at' => (int) $row['generated_at'], 'status' => (string) $row['status']];
        }

        return $rows;
    }

    /**
     * Result of dolifleet check for an instance (PHP, sudo, installed version...).
     *
     * @param array<string, mixed> $data
     */
    public function saveCheck(string $instance, array $data): void
    {
        $this->pdo->prepare('INSERT OR REPLACE INTO checks (instance, checked_at, data) VALUES (?, ?, ?)')
            ->execute([$instance, time(), json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    }

    /**
     * @return array{checked_at: int, data: array<string, mixed>}|null
     */
    public function check(string $instance): ?array
    {
        $statement = $this->pdo->prepare('SELECT checked_at, data FROM checks WHERE instance = ?');
        $statement->execute([$instance]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ( ! is_array($row)) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);

        return ['checked_at' => (int) $row['checked_at'], 'data' => is_array($data) ? $data : []];
    }
}
