<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database as DB;
use App\Core\Request;
use App\Core\View;

final class SearchController
{
    public function index(Request $r): string
    {
        $q = $r->str('q');
        $results = [];
        if (mb_strlen($q) >= 2) {
            $like = '%' . $q . '%';
            $results = DB::all(
                'SELECT m.id, m.title, m.meeting_date, m.status, m.summary,
                        (SELECT s.text FROM transcript_segments s WHERE s.meeting_id = m.id AND s.text LIKE ? ORDER BY s.position LIMIT 1) AS hit_segment,
                        (SELECT a.description FROM action_items a WHERE a.meeting_id = m.id AND a.description LIKE ? LIMIT 1) AS hit_task
                 FROM meetings m
                 WHERE m.title LIKE ? OR m.summary LIKE ? OR m.transcript_text LIKE ?
                    OR EXISTS (SELECT 1 FROM action_items a WHERE a.meeting_id = m.id AND a.description LIKE ?)
                    OR EXISTS (SELECT 1 FROM key_points k WHERE k.meeting_id = m.id AND k.text LIKE ?)
                 ORDER BY m.meeting_date DESC LIMIT 50',
                [$like, $like, $like, $like, $like, $like, $like]
            );
        }
        return View::render('search/index', ['title' => 'Hľadať', 'q' => $q, 'results' => $results, 'active' => 'search']);
    }
}
