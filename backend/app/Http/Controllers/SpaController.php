<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class SpaController extends Controller
{
    public function __invoke(): RedirectResponse|Response
    {
        if (config('app.frontend_deployment') === 'external') {
            return redirect()->away((string) config('app.frontend_url'));
        }

        $spaIndex = public_path('app/index.html');

        if (! is_file($spaIndex)) {
            return response()->view('welcome');
        }

        return response(file_get_contents($spaIndex), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
