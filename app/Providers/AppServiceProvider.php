<?php

namespace App\Providers;

use App\Services\FirebasePushSender;
use App\Services\LogPushSender;
use App\Services\PushSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pakai Firebase kalau file service account ada, kalau tidak pakai pengirim log
        $this->app->bind(PushSender::class, function ($app) {
            $path = base_path(env('FIREBASE_CREDENTIALS', 'storage/app/firebase/service-account.json'));

            return is_file($path)
                ? $app->make(FirebasePushSender::class)
                : $app->make(LogPushSender::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
