<?php

namespace App\Providers;

use App\Services\RbacService;
use App\Services\SystemHealthService;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RbacService::class, fn () => new RbacService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            $settings = DB::table('settings')->where('id', 1)->first();
            if ($settings && $settings->brand_color) {
                config(['app.brand_color' => $settings->brand_color]);
                $hex = ltrim($settings->brand_color, '#');
                if (strlen($hex) === 3) {
                    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                }
                $rgb = hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
                config(['app.brand_color_rgb' => $rgb]);
            }
        } catch (\Throwable $e) {
            // DB may not exist yet (e.g. during CI composer install)
        }

        Blade::if('hasFeature', function (string $feature) {
            $user = auth()->user();
            if (! $user) {
                return false;
            }

            return app(RbacService::class)->hasFeature($user->role, $feature);
        });

        Blade::if('hasDataAccess', function (string $permission) {
            $user = auth()->user();
            if (! $user) {
                return false;
            }

            return app(RbacService::class)->hasDataAccess($user->role, $permission);
        });

        Blade::if('isSuperAdmin', function () {
            $user = auth()->user();

            return $user && $user->role === 'super-admin';
        });

        Blade::directive('date', function (string $expression) {
            return "<?php echo \\App\\Support\\NepaliDate::display($expression); ?>";
        });

        Blade::directive('dateShort', function (string $expression) {
            return "<?php echo \\App\\Support\\NepaliDate::displayShort($expression); ?>";
        });

        Blade::directive('dateDayMonth', function (string $expression) {
            return "<?php echo \\App\\Support\\NepaliDate::displayDayMonth($expression); ?>";
        });

        Blade::directive('dateDateTime', function (string $expression) {
            return "<?php echo \\App\\Support\\NepaliDate::displayDateTime($expression); ?>";
        });

        Blade::directive('dateMonthYear', function (string $expression) {
            return "<?php echo \\App\\Support\\NepaliDate::displayMonthYear($expression); ?>";
        });

        Blade::directive('dateInput', function (string $expression) {
            return "<?php echo \\App\\Support\\NepaliDate::displayInputValue($expression); ?>";
        });

        // Persist scheduler run history for the System Health tab.
        // Laravel doesn't track last-run out of the box — hook into
        // ScheduledTaskFinished and append to a JSON ledger.
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            app(SystemHealthService::class)->recordScheduledRun($event);
        });
    }
}
