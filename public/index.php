<?php

use App\Kernel;

// Timezone Europe/Paris pour que les comparaisons d'heures soient correctes
date_default_timezone_set('Europe/Paris');

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
