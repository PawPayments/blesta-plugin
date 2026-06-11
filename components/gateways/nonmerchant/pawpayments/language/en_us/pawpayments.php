<?php
/**
 * en_us language for the PawPayments gateway.
 */
// Basics
$lang['Pawpayments.name'] = 'PawPayments (Crypto)';
$lang['Pawpayments.description'] = 'Accept cryptocurrency payments via PawPayments. Customers pay on the hosted paywall and invoices are reconciled automatically via signed webhooks.';

// Settings
$lang['Pawpayments.meta.api_key'] = 'API Key';
$lang['Pawpayments.meta.api_base_url'] = 'API Base URL';
$lang['Pawpayments.meta.ttl'] = 'Invoice TTL (seconds)';

$lang['Pawpayments.meta.api_key_note'] = 'Your PawPayments API key from the merchant dashboard.';
$lang['Pawpayments.meta.api_base_url_note'] = 'Leave as the default unless instructed otherwise.';
$lang['Pawpayments.meta.ttl_note'] = 'How long a payment invoice stays open, in seconds (300–86400).';

// Webhook
$lang['Pawpayments.webhook'] = 'PawPayments Webhook';
$lang['Pawpayments.webhook_note'] = 'PawPayments automatically delivers signed webhooks to the following URL — no manual configuration is required, but it must be reachable over HTTPS from the public internet:';

// Process
$lang['Pawpayments.buildprocess.submit'] = 'Pay with Crypto';

// Errors
$lang['Pawpayments.!error.api_key.empty'] = 'Please enter your PawPayments API Key.';
$lang['Pawpayments.!error.api_base_url.valid'] = 'Please enter a valid API Base URL.';
$lang['Pawpayments.!error.ttl.valid'] = 'Invoice TTL must be a whole number of seconds between 300 and 86400.';
$lang['Pawpayments.!error.create.failed'] = 'The payment could not be created. Please try again or contact support.';
$lang['Pawpayments.!error.signature.invalid'] = 'The webhook signature could not be verified.';
