<?php
/** @author Duplika https://duplika.com/ | @copyright 2026 Duplika | @license MIT */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
require_once __DIR__ . '/liquidweb.php';
require_once __DIR__ . '/lib/Notice.php';

// Runs on the authorized service page even when the module template is hidden
// for suspended services. No JavaScript, theme overrides or Liquid Web calls.
add_hook('ClientAreaProductDetailsOutput', 1, function ($vars) {
    $service = $vars['service'] ?? null;
    if (!$service instanceof \WHMCS\Service\Service) { return ''; }
    try {
        return \Duplika\LiquidWeb\Notice::forService((int) $service->id);
    } catch (\Throwable $e) {
        // WHMCS's native suspension notice remains available on database errors.
        return '';
    }
});
