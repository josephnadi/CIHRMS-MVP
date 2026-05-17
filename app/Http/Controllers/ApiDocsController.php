<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class ApiDocsController extends Controller
{
    public function show(): View
    {
        return view('api-docs');
    }
}
