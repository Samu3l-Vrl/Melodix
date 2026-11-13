<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Planification
|--------------------------------------------------------------------------
| Exécutée par `php artisan schedule:work` (service "scheduler" du docker-compose)
| ou par un cron `* * * * * php artisan schedule:run` en production.
*/

// Ferme les salons sans activité depuis 12 h. Toutes les heures : un salon vit donc au plus ~13 h sans activité.
Schedule::command('rooms:close-inactive')->hourly()->withoutOverlapping();
