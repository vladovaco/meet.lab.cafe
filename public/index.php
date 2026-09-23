<?php
declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\CronController;
use App\Controllers\DashboardController;
use App\Controllers\MeetingController;
use App\Controllers\ParticipantController;
use App\Controllers\SearchController;
use App\Controllers\SettingsController;
use App\Controllers\TagController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;

// vstavaný PHP server (php -S localhost:8080 -t public public/index.php): statické súbory obslúž priamo
if (PHP_SAPI === 'cli-server') {
    $static = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($static)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

session_name('meetlab');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$router = new Router();

// Auth
$router->get('/login', [AuthController::class, 'showLogin'], auth: false);
$router->post('/login', [AuthController::class, 'login'], auth: false);
$router->post('/logout', [AuthController::class, 'logout']);

// Dashboard / porady
$router->get('/', [DashboardController::class, 'index']);
$router->get('/meetings/new', [MeetingController::class, 'create']);
$router->post('/meetings', [MeetingController::class, 'store']);
$router->get('/meetings/{id}', [MeetingController::class, 'show']);
$router->get('/meetings/{id}/edit', [MeetingController::class, 'edit']);
$router->post('/meetings/{id}', [MeetingController::class, 'update']);
$router->post('/meetings/{id}/delete', [MeetingController::class, 'destroy']);
$router->post('/meetings/{id}/reprocess', [MeetingController::class, 'reprocess']);
$router->get('/meetings/{id}/audio', [MeetingController::class, 'audio']);
$router->post('/meetings/{id}/email', [MeetingController::class, 'email']);
$router->get('/meetings/{id}/export.md', [MeetingController::class, 'exportMarkdown']);
$router->get('/meetings/{id}/export.txt', [MeetingController::class, 'exportTranscript']);

// Účastníci, priečinky, štítky
$router->get('/participants', [ParticipantController::class, 'index']);
$router->post('/participants', [ParticipantController::class, 'store']);
$router->get('/participants/{id}', [ParticipantController::class, 'show']);
$router->post('/participants/{id}', [ParticipantController::class, 'update']);
$router->post('/participants/{id}/delete', [ParticipantController::class, 'destroy']);
$router->post('/participants/{id}/merge', [ParticipantController::class, 'merge']);
$router->get('/organise', [TagController::class, 'index']);
$router->post('/folders', [TagController::class, 'storeFolder']);
$router->post('/folders/{id}/delete', [TagController::class, 'deleteFolder']);
$router->post('/tags', [TagController::class, 'storeTag']);
$router->post('/tags/{id}/delete', [TagController::class, 'deleteTag']);

// Úlohy + hľadanie + nastavenia
$router->get('/tasks', [DashboardController::class, 'tasks']);
$router->get('/search', [SearchController::class, 'index']);
$router->get('/settings', [SettingsController::class, 'index']);
$router->post('/settings/password', [SettingsController::class, 'changePassword']);
$router->post('/settings/notify', [SettingsController::class, 'toggleNotify']);
$router->get('/admin/costs', [AdminController::class, 'costs']);
$router->post('/settings/users', [SettingsController::class, 'createUser']);

// Cron cez URL (hosting bez SSH)
$router->get('/cron/run', [CronController::class, 'run'], auth: false);

// JSON API (AJAX z prehliadača)
$router->post('/api/meetings/upload', [ApiController::class, 'upload']);
$router->get('/api/meetings/{id}/status', [ApiController::class, 'status']);
$router->post('/api/jobs/run', [ApiController::class, 'runJobs']);
$router->post('/api/action-items/{id}/toggle', [ApiController::class, 'toggleActionItem']);
$router->post('/api/action-items/{id}/update', [ApiController::class, 'updateActionItem']);
$router->post('/api/action-items/{id}/delete', [ApiController::class, 'deleteActionItem']);
$router->post('/api/meetings/{id}/action-items', [ApiController::class, 'addActionItem']);
$router->post('/api/meetings/{id}/speakers', [ApiController::class, 'assignSpeaker']);
$router->post('/api/meetings/{id}/segments/{segId}', [ApiController::class, 'updateSegment']);
$router->post('/api/meetings/{id}/summary', [ApiController::class, 'updateSummary']);
$router->post('/api/participants/quick', [ApiController::class, 'quickParticipant']);

try {
    $router->dispatch(new Request());
} catch (\Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    $isAjax = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    $msg = Config::get('env') === 'development' ? $e->getMessage() . "\n" . $e->getTraceAsString() : 'Nastala neočakávaná chyba.';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        echo View::render('errors/404', ['message' => $msg]);
    }
}
