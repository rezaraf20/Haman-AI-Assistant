<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {}
    public function boot(): void {
        if($this->app->isProduction()) URL::forceScheme('https');
        Model::preventLazyLoading(!$this->app->isProduction());
        \DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
