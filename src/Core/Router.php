<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:callable|array,auth:bool}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler, bool $auth = true): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'pattern', 'regex', 'handler', 'auth');
    }

    public function get(string $p, callable|array $h, bool $auth = true): void { $this->add('GET', $p, $h, $auth); }
    public function post(string $p, callable|array $h, bool $auth = true): void { $this->add('POST', $p, $h, $auth); }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($route['auth'] && !Auth::check()) {
                if ($request->isAjax()) {
                    Response::json(['error' => 'Nie ste prihlásený.'], 401);
                }
                $_SESSION['intended'] = $request->path;
                Response::redirect('/login');
            }
            if ($request->method === 'POST' && !Csrf::verify($request)) {
                if ($request->isAjax()) {
                    Response::json(['error' => 'Neplatný CSRF token, obnovte stránku.'], 419);
                }
                http_response_code(419);
                echo View::render('errors/404', ['message' => 'Neplatný bezpečnostný token. Obnovte stránku a skúste znova.']);
                return;
            }

            $handler = $route['handler'];
            if (is_array($handler) && is_string($handler[0])) {
                $handler = [new $handler[0](), $handler[1]];
            }
            $result = $handler($request);
            if (is_string($result)) {
                echo $result;
            }
            return;
        }
        Response::notFound();
    }
}
