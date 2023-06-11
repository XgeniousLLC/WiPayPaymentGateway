<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/* frontend routes */
Route::prefix('wipaypoaymentgateway')->group(function() {
    Route::get("landlord-price-plan-wipay",[\Modules\WiPayPaymentGateway\Http\Controllers\WiPayPaymentGatewayController::class,"landlordPricePlanIpn"])
        ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
        ->name("wipaypoaymentgateway.landlord.price.plan.ipn");

});


/* tenant payment ipn route*/
Route::middleware([
    'web',
    \App\Http\Middleware\Tenant\InitializeTenancyByDomainCustomisedMiddleware::class,
    PreventAccessFromCentralDomains::class
])->prefix('wipaypoaymentgateway')->group(function () {
    Route::post("tenant-price-plan-wipay",[\Modules\WiPayPaymentGateway\Http\Controllers\WiPayPaymentGatewayController::class,"TenantSiteswayIpn"])
        ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
        ->name("wipaypoaymentgateway.tenant.price.plan.ipn");

});

/* admin panel routes landlord */
Route::group(['middleware' => ['auth:admin','adminglobalVariable', 'set_lang'],'prefix' => 'admin-home'],function () {
    Route::prefix('wipaypoaymentgateway')->group(function() {
        Route::get('/settings', [\Modules\WiPayPaymentGateway\Http\Controllers\WiPayPaymentGatewayAdminPanelController::class,"settings"])
            ->name("wipaypoaymentgateway.landlord.admin.settings");
        Route::post('/settings', [\Modules\WiPayPaymentGateway\Http\Controllers\WiPayPaymentGatewayAdminPanelController::class,"settingsUpdate"]);
    });
});


Route::group(['middleware' => [
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    'auth:admin',
    'tenant_admin_glvar',
    'package_expire',
    'tenantAdminPanelMailVerify',
    'tenant_status',
    'set_lang'
    ],'prefix' => 'admin-home'],function () {
    Route::prefix('wipaypoaymentgateway/tenant')->group(function() {
        Route::get('/settings', [\Modules\WiPayPaymentGateway\Http\Controllers\WiPayPaymentGatewayAdminPanelController::class,"settings"])
            ->name("wipaypoaymentgateway.tenant.admin.settings");
        Route::post('/settings', [\Modules\WiPayPaymentGateway\Http\Controllers\WiPayPaymentGatewayAdminPanelController::class,"settingsUpdate"]);
    });
});

