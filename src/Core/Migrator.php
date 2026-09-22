<?php
declare(strict_types=1);

namespace App\Core;

final class Migrator
{
    /** Spustí schema.sql (idempotentné CREATE) a doplní chýbajúce stĺpce. @return list<string> správy */
    public static function run(): array
    {
        $root = (string) Config::get('root');
        $out = [];
        Database::pdo()->exec((string) file_get_contents($root . '/database/schema.sql'));
        $out[] = 'Schéma databázy vytvorená/aktualizovaná.';
        $migrations = require $root . '/database/migrations.php';
        foreach ($migrations as [$table, $column, $sql]) {
            $exists = Database::one(
                'SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            if ($exists === null) {
                Database::pdo()->exec($sql);
                $out[] = "Pridaný stĺpec $table.$column.";
            }
        }
        return $out;
    }
}
