<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\ActionItem;
use App\Models\Meeting;
use App\Models\Participant;
use App\Models\Tag;

final class DashboardController
{
    public function index(Request $r): string
    {
        $filters = [
            'folder_id'      => $r->int('folder'),
            'tag_id'         => $r->int('tag'),
            'participant_id' => $r->int('participant'),
            'status'         => $r->str('status'),
            'q'              => $r->str('q'),
        ];
        $page = max(1, $r->int('page', 1));
        $perPage = 20;
        $meetings = Meeting::list($filters, $perPage + 1, ($page - 1) * $perPage);
        $hasMore = count($meetings) > $perPage;
        $meetings = array_slice($meetings, 0, $perPage);

        return View::render('dashboard/index', [
            'title'        => 'Porady',
            'meetings'     => $meetings,
            'filters'      => $filters,
            'folders'      => Tag::folders(),
            'tags'         => Tag::all(),
            'participants' => Participant::all(),
            'counts'       => Meeting::counts(),
            'page'         => $page,
            'hasMore'      => $hasMore,
            'active'       => 'meetings',
        ]);
    }

    public function tasks(Request $r): string
    {
        $items = ActionItem::open(300);
        $byPerson = [];
        foreach ($items as $it) {
            $key = $it['participant_name'] ?? ($it['assignee_name'] ?: 'Nepriradené');
            $byPerson[$key][] = $it;
        }
        ksort($byPerson, SORT_LOCALE_STRING);
        if (isset($byPerson['Nepriradené'])) {
            $tmp = $byPerson['Nepriradené'];
            unset($byPerson['Nepriradené']);
            $byPerson['Nepriradené'] = $tmp;
        }
        return View::render('dashboard/tasks', ['title' => 'Úlohy', 'byPerson' => $byPerson, 'total' => count($items), 'active' => 'tasks']);
    }
}
