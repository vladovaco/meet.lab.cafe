<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Tag
{
    public static function all(): array
    {
        return DB::all('SELECT t.*, (SELECT COUNT(*) FROM meeting_tags mt WHERE mt.tag_id = t.id) AS meetings_count FROM tags t ORDER BY t.name');
    }

    public static function folders(): array
    {
        return DB::all('SELECT f.*, (SELECT COUNT(*) FROM meetings m WHERE m.folder_id = f.id) AS meetings_count FROM folders f ORDER BY f.name');
    }

    /** Vráti id štítku podľa mena; ak neexistuje, vytvorí ho. */
    public static function ensure(string $name): int
    {
        $name = trim(mb_substr($name, 0, 80));
        $row = DB::one('SELECT id FROM tags WHERE name = ?', [$name]);
        if ($row) {
            return (int) $row['id'];
        }
        return DB::insert('tags', ['name' => $name, 'color' => speaker_color(crc32($name) % 10)]);
    }
}
