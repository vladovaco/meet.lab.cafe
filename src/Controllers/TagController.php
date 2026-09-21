<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database as DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Tag;

final class TagController
{
    public function index(Request $r): string
    {
        return View::render('tags/index', ['title' => 'Triedenie', 'folders' => Tag::folders(), 'tags' => Tag::all(), 'active' => 'organise']);
    }

    public function storeFolder(Request $r): void
    {
        $name = $r->str('name');
        if ($name !== '') {
            DB::insert('folders', ['name' => mb_substr($name, 0, 120), 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $r->str('color')) ? $r->str('color') : '#6366f1']);
            Flash::set('success', 'Priečinok bol vytvorený.');
        }
        Response::redirect('/organise');
    }

    public function deleteFolder(Request $r): void
    {
        DB::run('DELETE FROM folders WHERE id = ?', [(int) $r->param('id')]);
        Flash::set('success', 'Priečinok bol zmazaný (porady ostali bez priečinka).');
        Response::redirect('/organise');
    }

    public function storeTag(Request $r): void
    {
        $name = trim(mb_strtolower($r->str('name')));
        if ($name !== '') {
            $id = Tag::ensure($name);
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $r->str('color'))) {
                DB::update('tags', ['color' => $r->str('color')], 'id = ?', [$id]);
            }
            Flash::set('success', 'Štítok bol uložený.');
        }
        Response::redirect('/organise');
    }

    public function deleteTag(Request $r): void
    {
        DB::run('DELETE FROM tags WHERE id = ?', [(int) $r->param('id')]);
        Flash::set('success', 'Štítok bol zmazaný.');
        Response::redirect('/organise');
    }
}
