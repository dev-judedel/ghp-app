<?php

namespace App\Providers;

use App\Support\OptionLabels;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Default pagination views assume Tailwind is loaded via a build
        // step we deliberately skipped (offline environment — see project
        // notes). Use our hand-written view instead.
        Paginator::defaultView('vendor.pagination.custom');
        Paginator::defaultSimpleView('vendor.pagination.custom');

        // Department / Division names are not unique (task.md §2.36). Every screen
        // with a Department or Division <select> submits the record's id; this
        // only adds a visible "#id" to the LABEL of a name that repeats.
        View::composer(['members.index', 'members.show', 'reports.index', 'users.index'], function (ViewContract $view) {
            $data = $view->getData();

            if (($data['departments'] ?? null) instanceof Collection) {
                OptionLabels::departments($data['departments']);
            }

            if (($data['divisions'] ?? null) instanceof Collection) {
                OptionLabels::divisions($data['divisions']);
            }
        });
    }
}
