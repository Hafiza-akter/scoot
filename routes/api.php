<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\api\v1\AirShoppingController;
use App\Http\Controllers\api\v1\OrderCancelController;
use App\Http\Controllers\api\v1\OrderChangeController;
use App\Http\Controllers\api\v1\ServiceListController;
use App\Http\Controllers\api\v1\SeatAvailabilityController;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::get("/health", function () {
    return response()->json(["message" => "App health is ok."]);
})->name('health');




Route::prefix('ndc/v1')->name("ndc.v1.sc.")->group(function () {
        Route::post("/sc/air_shopping", AirShoppingController::class)->name('air_shopping');
        Route::post("/sc/service_list", ServiceListController::class)->name('service_list');
        Route::any("/sc/seat_availability", SeatAvailabilityController::class)->name('seat_availability');
        Route::post("/sc/order_change", OrderChangeController::class)->name('order_change');
        Route::post("/sc/order_cancel", OrderCancelController::class)->name('order_cancel');
});

