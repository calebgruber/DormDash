<?php
require_once __DIR__ . '/db.php';

define('MIGRATIONS_DIR', __DIR__ . '/../sql/migrations/');

/**
 * Create the migrations tracking table if it does not already exist.
 */
function ensureMigrationsTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS `migrations` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `filename`   VARCHAR(255) NOT NULL UNIQUE,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Return sorted list of .sql filenames found in the migrations directory.
 */
function getMigrationFiles(): array
{
    if (!is_dir(MIGRATIONS_DIR)) {
        return [];
    }
    $files = glob(MIGRATIONS_DIR . '*.sql') ?: [];
    sort($files);
    return array_map('basename', $files);
}

/**
 * Return filenames of migrations that have already been applied.
 */
function getAppliedMigrations(PDO $db): array
{
    try {
        ensureMigrationsTable($db);
        $stmt = $db->query('SELECT filename FROM migrations ORDER BY id ASC');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Return filenames of migrations not yet applied, in order.
 */
function getPendingMigrations(PDO $db): array
{
    $all     = getMigrationFiles();
    $applied = getAppliedMigrations($db);
    return array_values(array_diff($all, $applied));
}

/**
 * Execute a single migration file and record it.
 * Returns ['success' => bool, 'error' => string|null].
 */
function runMigration(PDO $db, string $filename): array
{
    $filepath = MIGRATIONS_DIR . $filename;
    if (!file_exists($filepath)) {
        return ['success' => false, 'error' => "File not found: {$filename}"];
    }

    $sql = file_get_contents($filepath);
    if ($sql === false || trim($sql) === '') {
        return ['success' => false, 'error' => "Cannot read or empty file: {$filename}"];
    }

    try {
        $db->exec($sql);
        $db->prepare('INSERT IGNORE INTO migrations (filename) VALUES (?)')->execute([$filename]);
        return ['success' => true, 'error' => null];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Run all pending migrations in order.
 * Stops on the first failure.
 * Returns an associative array: [ filename => ['success', 'error'] ]
 */
function runAllPendingMigrations(PDO $db): array
{
    $pending = getPendingMigrations($db);
    $results = [];

    foreach ($pending as $filename) {
        $result             = runMigration($db, $filename);
        $results[$filename] = $result;
        if (!$result['success']) {
            break; // Stop on first error
        }
    }

    return $results;
}

/**
 * Return full detail rows for applied migrations (id, filename, applied_at).
 */
function getAppliedMigrationDetails(PDO $db): array
{
    try {
        ensureMigrationsTable($db);
        $stmt = $db->query('SELECT * FROM migrations ORDER BY id ASC');
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}
