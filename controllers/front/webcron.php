<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/cron.php';

// Use the same authentication, shop validation, locking and JSON responses as
// the server cron endpoint, so both entry points always behave consistently.
class AutostockWebcronModuleFrontController extends AutostockCronModuleFrontController
{
}
