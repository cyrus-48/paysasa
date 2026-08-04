<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Paysasa\Payments\Http\Controllers\WebhookController;
use Paysasa\Payments\Middleware\ThrottleWebhooks;

/*
|--------------------------------------------------------------------------
| Paysasa Webhook Routes
|--------------------------------------------------------------------------
|
| Registered by PaysasaServiceProvider under the prefix configured in
| config('paysasa.webhook_route_prefix') (default: paysasa/webhooks), e.g.
| POST https://yourapp.test/paysasa/webhooks/mpesa
|
| These routes are intentionally excluded from Laravel's default 'web'
| middleware group (no CSRF, no session) since providers never send a CSRF
| token — see config('paysasa.routes.middleware').
|
*/

Route::middleware([ThrottleWebhooks::class])->group(function () {
    Route::match(['get', 'post'], '/{provider}', [WebhookController::class, 'handle'])
        ->where('provider', '[a-z_]+')
        ->name('paysasa.webhooks.handle');
});
