<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/form', 'form');

Route::post('/contact', function (Request $request) {
    $result = ['sent' => true, 'seconds' => $request->attributes->get('spam-strike.seconds'), 'name' => $request->input('name')];

    return $request->expectsJson() ? response()->json($result) : back()->with('result', $result);
})->middleware('spam-strike');
