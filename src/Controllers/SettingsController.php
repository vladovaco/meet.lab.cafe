<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database as DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Job;
use App\Services\Analysis\AnalyzerFactory;

final class SettingsController
{
    public function index(Request $r): string
    {
        return View::render('settings/index', [
            'title'   => 'Nastavenia',
            'user'    => Auth::user(),
            'users'   => Auth::isAdmin() ? DB::all('SELECT id, email, name, role, last_login_at FROM users ORDER BY name') : [],
            'config'  => [
                'stt_provider'   => Config::get('stt.provider'),
                'stt_configured' => Config::get('stt.provider') === 'assemblyai' ? (bool) Config::get('stt.assemblyai_key') : (bool) Config::get('stt.elevenlabs_key'),
                'ai_model'       => AnalyzerFactory::label(),
                'ai_configured'  => AnalyzerFactory::configured(),
                'process_mode'   => Config::get('process_mode'),
                'max_upload_mb'  => Config::get('max_upload_mb'),
                'php_upload_max' => ini_get('upload_max_filesize'),
                'php_post_max'   => ini_get('post_max_size'),
            ],
            'pendingJobs' => Job::pendingCount(),
            'failedJobs'  => DB::all('SELECT j.*, m.title FROM jobs j JOIN meetings m ON m.id = j.meeting_id WHERE j.status = "failed" ORDER BY j.id DESC LIMIT 10'),
            'active'  => 'settings',
        ]);
    }

    public function changePassword(Request $r): void
    {
        $user = Auth::user();
        $row = DB::one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!password_verify((string) $r->input('current', ''), $row['password_hash'] ?? '')) {
            Flash::set('error', 'Súčasné heslo nie je správne.');
        } elseif (mb_strlen((string) $r->input('new', '')) < 8) {
            Flash::set('error', 'Nové heslo musí mať aspoň 8 znakov.');
        } else {
            DB::update('users', ['password_hash' => password_hash((string) $r->input('new'), PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
            Flash::set('success', 'Heslo bolo zmenené.');
        }
        Response::redirect('/settings');
    }

    public function createUser(Request $r): void
    {
        if (!Auth::isAdmin()) {
            Response::redirect('/settings');
        }
        $email = mb_strtolower($r->str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen((string) $r->input('password', '')) < 8) {
            Flash::set('error', 'Zadajte platný e-mail a heslo (min. 8 znakov).');
        } elseif (DB::one('SELECT id FROM users WHERE email = ?', [$email])) {
            Flash::set('error', 'Používateľ s týmto e-mailom už existuje.');
        } else {
            DB::insert('users', ['email' => $email, 'name' => mb_substr($r->str('name') ?: $email, 0, 120), 'password_hash' => password_hash((string) $r->input('password'), PASSWORD_DEFAULT), 'role' => $r->str('role') === 'admin' ? 'admin' : 'member']);
            Flash::set('success', 'Používateľ bol vytvorený.');
        }
        Response::redirect('/settings');
    }
}
