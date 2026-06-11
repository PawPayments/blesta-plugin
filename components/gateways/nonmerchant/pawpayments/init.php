<?php
/**
 * Loads the vendored PawPayments PHP SDK (no Composer autoloader is shipped,
 * so the classes are required explicitly in dependency order).
 */

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR
    . 'vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR
    . 'vendor/pawpayments/sdk/src/Version.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR
    . 'vendor/pawpayments/sdk/src/PawPaymentsClient.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR
    . 'vendor/pawpayments/sdk/src/Webhook.php';
