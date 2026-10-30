<?php
// Offline checks only. Run with: php tests/smoke.php
define('WHMCS', true);
require __DIR__ . '/../modules/servers/liquidweb/liquidweb.php';
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
$api = new \Duplika\LiquidWeb\Api('fixture-user', 'fixture-secret');
foreach (['server/terminate', 'server/power/reboot', '../asset/details', 'server/create'] as $operation) {
    try {
        $api->call($operation, []);
        throw new RuntimeException('Mutation allowed: ' . $operation);
    } catch (InvalidArgumentException $expected) {
    }
}
$public = liquidweb_publicDetails([
    'domain' => 'cloud.example.com', 'ip' => '192.0.2.1', 'status' => 'Running',
    'price' => 999, 'username' => 'secret-user', 'password' => 'secret', 'type' => ['invalid'],
]);
check(count($public) === 1, 'Only scalar public fields may be displayed without duplicate host/IP');
check(!in_array('secret', $public, true), 'Secrets must not reach Smarty');
check(!isset($public['Hostname']) && !isset($public['IP']), 'Native WHMCS fields must not be repeated');
check(liquidweb_displayValue('type', 'SS.VPS') === 'VPS', 'Translate the observed VPS code');
check(liquidweb_displayValue('status', 'Active') === 'Active', 'Translate the observed resource status');
check(liquidweb_displayValue('power', 'on') === 'On', 'Translate power without claiming service availability');
check(liquidweb_displayValue('status', 'future-status') === 'future-status', 'Preserve unknown states');
foreach (['CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount', 'ChangePackage', 'ChangePassword', 'Renew'] as $action) {
    check(!function_exists('liquidweb_' . $action), 'Unexpected lifecycle handler');
}
check(liquidweb_MetaData()['RequiresServer'] === true, 'A server connection is required');
$moduleSource = file_get_contents(__DIR__ . '/../modules/servers/liquidweb/liquidweb.php');
check(strpos($moduleSource, '&lw_network=1#') === false, 'Network links must not change the theme active-tab fragment');
$calls = [];
liquidweb_connectionProbe(function ($method, $params) use (&$calls) {
    $calls[] = [$method, $params];
    if ($method === 'asset/list') {
        return ['items' => [['uniq_id' => 'ABC123']]];
    }
    return ['uniq_id' => 'ABC123'];
});
check(count($calls) === 2, 'The probe must make two read calls for a nonempty account');
check($calls[0] === ['asset/list', ['page_size' => 1, 'page_num' => 1]], 'Limit inventory to one asset');
check($calls[1] === ['asset/details', ['uniq_id' => 'ABC123']], 'Probe the actual returned UID');
liquidweb_connectionProbe(function () { return ['items' => []]; });
foreach ([[], ['permissions' => []], ['items' => [['uniq_id' => '../bad']]]] as $invalid) {
    $failed = false;
    try { liquidweb_connectionProbe(function () use ($invalid) { return $invalid; }); }
    catch (RuntimeException $expected) { $failed = true; }
    check($failed, 'Malformed replies must not pass the connection test');
}
$failed = false;
try {
    liquidweb_connectionProbe(function ($method) {
        return $method === 'asset/list' ? ['items' => [['uniq_id' => 'ABC123']]] : ['uniq_id' => 'XYZ789'];
    });
} catch (RuntimeException $expected) { $failed = true; }
check($failed, 'A mismatched asset must fail');
$failed = false;
try { liquidweb_connectionProbe(function () { throw new RuntimeException('HTTP 403'); }); }
catch (RuntimeException $expected) { $failed = true; }
check($failed, 'An upstream denial must fail');
$networkFixture = ['data' => [
    ['time' => '1793298900', 'points' => [
        ['name' => 'incoming', 'unit' => 'bytes', 'value' => 110029],
        ['name' => 'outgoing', 'unit' => 'bytes', 'value' => 481555],
    ]],
    ['time' => '1793299200', 'points' => [
        ['name' => 'incoming', 'unit' => 'bytes', 'value' => 77624],
        ['name' => 'outgoing', 'unit' => 'bytes', 'value' => 462389],
    ]],
]];
$chart = \Duplika\LiquidWeb\Network::render($networkFixture);
check($chart['count'] === 2 && $chart['interval'] === 300, 'Preserve the reported five-minute sampling');
$svg = base64_decode(substr($chart['image'], strlen('data:image/svg+xml;base64,')), true);
check(is_string($svg) && strpos($svg, '<svg ') === 0, 'A local SVG must be returned');
check(strpos($svg, 'KiB / sample') !== false, 'Label the original values per sample, not per second');
check(strpos($svg, '<script') === false && strpos($svg, 'onload=') === false, 'No active SVG content');
$reversed = ['data' => array_reverse($networkFixture['data'])];
check(\Duplika\LiquidWeb\Network::render($reversed) === $chart, 'Sort out-of-order timestamps');
$wrongUnits = $networkFixture;
foreach ($wrongUnits['data'] as &$row) {
    foreach ($row['points'] as &$point) { $point['unit'] = 'bytes/s'; }
    unset($point);
}
unset($row);
foreach ([[], ['data' => []], $wrongUnits] as $invalid) {
    $failed = false;
    try { \Duplika\LiquidWeb\Network::render($invalid); }
    catch (RuntimeException $expected) { $failed = true; }
    check($failed, 'Missing data and changed units must fail instead of drawing misleading values');
}
$injection = $networkFixture;
$injection['data'][0]['points'][] = ['name' => '<script>alert(1)</script>', 'unit' => 'bytes', 'value' => 42];
check(\Duplika\LiquidWeb\Network::render($injection) === $chart, 'Unrecognized remote names must not be embedded');

$location = liquidweb_publicDetails([
    'distro' => 'AlmaLinux 9',
    'region' => ['city' => 'Lansing', 'country' => 'US', 'name' => 'US Central', 'asn' => 32244],
    'monitorableServices' => [['name' => 'http', 'port' => 80]],
]);
check($location['Location'] === 'Lansing, United States', 'Present the reported location');
check($location['Region'] === 'US Central', 'Preserve the provider region label');
check($location['Operating system'] === 'AlmaLinux 9', 'Present a scalar distro without inferring usage');
check(count($location) === 3, 'Monitoring configuration must not be presented as health');
check(liquidweb_publicDetails(['distro' => ['unexpected'], 'region' => 'unexpected']) === [], 'Ignore malformed metadata');
echo "Offline checks passed. API and WHMCS integration remain untested.\n";
