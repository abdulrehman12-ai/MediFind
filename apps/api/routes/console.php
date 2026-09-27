<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('prescriptions:prune-orphans')->daily();
Schedule::command('prescriptions:recover-claims')->everyMinute();
