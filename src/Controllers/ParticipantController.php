<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database as DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\ActionItem;
use App\Models\Meeting;
use App\Models\Participant;

final class ParticipantController
{
    public function index(Request $r): string
    {
        return View::render('participants/index', ['title' => 'Účastníci', 'participants' => Participant::all(), 'active' => 'participants']);
    }

    public function store(Request $r): void
    {
        $name = $r->str('name');
        if ($name === '') {
            Flash::set('error', 'Meno je povinné.');
            Response::redirect('/participants');
        }
        $id = Participant::create([
            'name'         => mb_substr($name, 0, 120),
            'email'        => mb_substr($r->str('email'), 0, 190) ?: null,
            'organisation' => mb_substr($r->str('organisation'), 0, 120) ?: null,
            'position'     => mb_substr($r->str('position'), 0, 120) ?: null,
            'aliases'      => mb_substr($r->str('aliases'), 0, 255) ?: null,
        ]);
        Flash::set('success', 'Účastník bol pridaný.');
        Response::redirect('/participants/' . $id);
    }

    public function show(Request $r): string
    {
        $p = Participant::find((int) $r->param('id'));
        if ($p === null) {
            Response::notFound('Účastník sa nenašiel');
        }
        return View::render('participants/show', [
            'title'        => $p['name'],
            'participant'  => $p,
            'stats'        => Participant::stats((int) $p['id']),
            'meetings'     => Meeting::list(['participant_id' => $p['id']], 100),
            'tasks'        => ActionItem::forParticipant((int) $p['id']),
            'others'       => array_values(array_filter(Participant::all(), fn($o) => (int) $o['id'] !== (int) $p['id'])),
            'active'       => 'participants',
        ]);
    }

    public function update(Request $r): void
    {
        $p = Participant::find((int) $r->param('id'));
        if ($p === null) {
            Response::notFound();
        }
        DB::update('participants', [
            'name'         => mb_substr($r->str('name') ?: $p['name'], 0, 120),
            'email'        => mb_substr($r->str('email'), 0, 190) ?: null,
            'organisation' => mb_substr($r->str('organisation'), 0, 120) ?: null,
            'position'     => mb_substr($r->str('position'), 0, 120) ?: null,
            'aliases'      => mb_substr($r->str('aliases'), 0, 255) ?: null,
            'notes'        => $r->str('notes') ?: null,
            'color'        => preg_match('/^#[0-9a-fA-F]{6}$/', $r->str('color')) ? $r->str('color') : $p['color'],
        ], 'id = ?', [$p['id']]);
        Flash::set('success', 'Účastník bol uložený.');
        Response::redirect('/participants/' . $p['id']);
    }

    public function destroy(Request $r): void
    {
        DB::run('DELETE FROM participants WHERE id = ?', [(int) $r->param('id')]);
        Flash::set('success', 'Účastník bol zmazaný.');
        Response::redirect('/participants');
    }

    /** Zlúči duplicitného účastníka do iného (prenesie rečníkov, úlohy, účasť). */
    public function merge(Request $r): void
    {
        $from = (int) $r->param('id');
        $into = $r->int('into');
        if ($from === $into || Participant::find($from) === null || Participant::find($into) === null) {
            Flash::set('error', 'Neplatné zlúčenie.');
            Response::redirect('/participants/' . $from);
        }
        DB::run('UPDATE meeting_speakers SET participant_id = ? WHERE participant_id = ?', [$into, $from]);
        DB::run('UPDATE action_items SET participant_id = ? WHERE participant_id = ?', [$into, $from]);
        DB::run('INSERT IGNORE INTO meeting_participants (meeting_id, participant_id) SELECT meeting_id, ? FROM meeting_participants WHERE participant_id = ?', [$into, $from]);
        $src = Participant::find($from);
        $dst = Participant::find($into);
        $aliases = array_filter(array_map('trim', array_merge(explode(',', (string) $dst['aliases']), [$src['name']], explode(',', (string) $src['aliases']))));
        DB::update('participants', ['aliases' => mb_substr(implode(', ', array_unique($aliases)), 0, 255)], 'id = ?', [$into]);
        DB::run('DELETE FROM participants WHERE id = ?', [$from]);
        Flash::set('success', 'Účastníci boli zlúčení.');
        Response::redirect('/participants/' . $into);
    }
}
