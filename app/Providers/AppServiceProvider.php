<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Observers\AuditableObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        foreach (glob(app_path('Models/*.php')) ?: [] as $modelPath) {
            $modelClass = 'App\\Models\\' . pathinfo($modelPath, PATHINFO_FILENAME);

            if ($modelClass !== AuditLog::class
                && class_exists($modelClass)
                && is_subclass_of($modelClass, Model::class)) {
                $modelClass::observe(AuditableObserver::class);
            }
        }
    }
}
