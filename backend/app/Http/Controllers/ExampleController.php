<?php

namespace App\Http\Controllers;

class ExampleController extends Controller
{
    public function hello()
    {
        return response()->json([
            'message' => 'Hello from Lumen + PostgreSQL!',
            'time' => date('c'),
        ]);
    }
}
