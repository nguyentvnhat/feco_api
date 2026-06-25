<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Agent\App\Http\Controllers\ChildAgentController;
use Modules\Order\App\Http\Controllers\OrderController;

/*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register API routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | is assigned the "api" middleware group. Enjoy building your API!
    |
*/

Route::middleware(['auth:sanctum'])->prefix('v1')->name('api.')->group(function () {
    Route::get('agent', fn (Request $request) => $request->user())->name('agent');

    Route::get('agents/children', [ChildAgentController::class, 'index'])->name('agents.children');
    Route::get('agents/children/{childAgent}/orders', [OrderController::class, 'childAgentOrders'])->name('agents.children.orders');
});
