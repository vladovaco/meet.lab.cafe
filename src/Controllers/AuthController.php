<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class AuthController
{
    public function showLogin(Request $r): string
    {
        if (Auth::check()) {
            Response::redirect('/');
        }
        return View::render('auth/login', ['title' => 'Prihlásenie'], 'layout_plain');
    }

    public function login(Request $r): void
    {
        if (Auth::attempt($r->str('email'), (string) $r->input('password', ''))) {
            $to = $_SESSION['intended'] ?? '/';
            unset($_SESSION['intended']);
            Response::redirect($to);
        }
        Flash::set('error', 'Nesprávny e-mail alebo heslo.');
        Response::redirect('/login');
    }

    public function logout(Request $r): void
    {
        Auth::logout();
        Response::redirect('/login');
    }
}
