<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\CostTracker;

final class AdminController
{
    public function costs(Request $r): string
    {
        if (!Auth::isAdmin()) {
            Response::notFound('Stránka je dostupná iba administrátorovi.');
        }
        return View::render('admin/costs', ['title' => 'Náklady', 'summary' => CostTracker::summary(), 'pricing' => CostTracker::pricing(), 'active' => 'settings']);
    }
}
