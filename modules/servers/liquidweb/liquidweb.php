<?php
/**
 * Liquid Web for WHMCS — 0.2.0-beta.1
 * Server information and optional native firewall suspension.
 *
 * @author Duplika <https://duplika.com/>
 * @copyright 2026 Duplika
 * @license MIT
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}
require_once __DIR__ . '/lib/Api.php';
require_once __DIR__ . '/lib/Network.php';
require_once __DIR__ . '/lib/Firewall.php';
require_once __DIR__ . '/lib/State.php';

function liquidweb_MetaData(): array
{
    return ['DisplayName' => 'Liquid Web', 'APIVersion' => '1.1', 'RequiresServer' => true];
}

function liquidweb_ConfigOptions(): array
{
    return ['Firewall suspension' => [
        'Type' => 'yesno',
        'Description' => 'Enable native Suspend/Unsuspend using the Cloud Firewall. Monitoring must be paused manually.',
    ]];
}

function liquidweb_TestConnection(array $params): array
{
    try {
        $api = new \Duplika\LiquidWeb\Api((string) ($params['serverusername'] ?? ''), (string) ($params['serverpassword'] ?? ''));
        // Exercise documented read endpoints instead of guessing the shape of
        // account/user/permitted.permissions, which has no detailed schema.
        liquidweb_connectionProbe([$api, 'call']);
        return ['success' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Verify authentication and actual read access; never modify a remote asset. */
function liquidweb_connectionProbe(callable $request): void
{
    $data = $request('asset/list', ['page_size' => 1, 'page_num' => 1]);
    if (!isset($data['items']) || !is_array($data['items'])) {
        throw new \RuntimeException('The asset/list response does not contain a valid asset list.');
    }
    // An empty account still proves authenticated inventory access. Per-service
    // availability and power status are checked when opening the service.
    if ($data['items'] === []) {
        return;
    }
    $first = reset($data['items']);
    $uid = is_array($first) ? ($first['uniq_id'] ?? null) : null;
    if (!is_string($uid) || !preg_match('/^[A-HJ-NP-Z0-9]{6}$/D', $uid)) {
        throw new \RuntimeException('The first test asset does not have a valid UID.');
    }
    $asset = $request('asset/details', ['uniq_id' => $uid]);
    if (($asset['uniq_id'] ?? '') !== $uid) {
        throw new \RuntimeException('The test request did not return the expected asset.');
    }
}

/** Only a server-side, admin-only field may identify the remote asset. */
function liquidweb_assetId(array $params): string
{
    $field = \WHMCS\Database\Capsule::table('tblcustomfields')
        ->where('type', 'product')->where('relid', (int) $params['pid'])
        ->where('fieldname', 'LiquidWeb UID')->first();
    if (!$field || !in_array((string) $field->adminonly, ['on', '1'], true)) {
        throw new \RuntimeException('The admin-only LiquidWeb UID custom field is missing.');
    }
    $value = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', (int) $field->id)->where('relid', (int) $params['serviceid'])->value('value');
    $uid = strtoupper(trim((string) $value));
    if (!preg_match('/^[A-HJ-NP-Z0-9]{6}$/D', $uid)) {
        throw new \RuntimeException('Configure a valid LiquidWeb UID for this service.');
    }
    return $uid;
}

/** Explicitly select public fields. Prices, usernames and credentials never reach Smarty. */
function liquidweb_publicDetails(array $asset): array
{
    $fields = ['type' => 'Type', 'status' => 'Resource status'];
    $result = [];
    foreach ($fields as $key => $label) {
        if (isset($asset[$key]) && is_scalar($asset[$key]) && (string) $asset[$key] !== '') {
            $result[$label] = liquidweb_displayValue($key, (string) $asset[$key]);
        }
    }
    if (is_string($asset['distro'] ?? null) && trim($asset['distro']) !== '') {
        $result['Operating system'] = liquidweb_displayValue('distro', trim($asset['distro']));
    }
    $region = $asset['region'] ?? null;
    if (is_array($region)) {
        $location = [];
        foreach (['city', 'country'] as $field) {
            if (is_string($region[$field] ?? null) && trim($region[$field]) !== '') {
                $value = trim($region[$field]);
                $location[] = $field === 'country' && $value === 'US' ? 'United States' : $value;
            }
        }
        if ($location !== []) {
            $result['Location'] = implode(', ', $location);
        }
        if (is_string($region['name'] ?? null) && trim($region['name']) !== '') {
            $result['Region'] = trim($region['name']);
        }
    }
    return $result;
}

/** Translate known codes; preserve unknown values without guessing their meaning. */
function liquidweb_displayValue(string $field, string $value): string
{
    $labels = [
        'type' => ['SS.VPS' => 'VPS', 'Cloud.VPS' => 'VPS', 'Cloud.Metal' => 'Cloud dedicated server', 'Bare.Metal' => 'Dedicated server'],
        'status' => ['Active' => 'Active', 'New' => 'New', 'Disabled' => 'Disabled', 'Terminated' => 'Terminated', 'Pending-Activation' => 'Pending activation', 'Pending-Termination' => 'Pending termination'],
        'distro' => ['alma' => 'AlmaLinux'],
        'power' => ['on' => 'On', 'off' => 'Off'],
    ];
    return $labels[$field][$value] ?? $value;
}

function liquidweb_ClientArea(array $params): array
{
    // WHMCS dispatches this function after its normal service authorization.
    // There are no independent HTTP handlers or user-controlled remote IDs.
    $serviceStatus = \WHMCS\Database\Capsule::table('tblhosting')
        ->where('id', (int) $params['serviceid'])->where('userid', (int) $params['userid'])->value('domainstatus');
    if ($serviceStatus === 'Suspended') {
        return ['templatefile' => 'clientarea', 'vars' => ['lwSuspended' => true]];
    }
    $vars = ['lwSuspended' => false, 'lwRows' => [], 'lwError' => '', 'lwPower' => '', 'lwUpdated' => ''];
    try {
        $uid = liquidweb_assetId($params);
        $username = (string) ($params['serverusername'] ?? '');
        $password = (string) ($params['serverpassword'] ?? '');
        $key = 'en020b1-' . hash('sha256', json_encode([
            (int) $params['userid'], (int) $params['serviceid'], (int) $params['serverid'],
            $uid, $username, hash('sha256', $password),
        ]));
        $cached = $_SESSION['liquidweb'][$key] ?? null;
        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) {
            $vars = $cached['vars'];
        } else {
            $api = new \Duplika\LiquidWeb\Api($username, $password);
            $asset = $api->call('asset/details', ['uniq_id' => $uid, 'alsowith' => ['region', 'distro']]);
            if (($asset['uniq_id'] ?? '') !== $uid) {
                throw new \RuntimeException('The response does not match the assigned server.');
            }
            $vars['lwRows'] = liquidweb_publicDetails($asset);
            try {
                $power = $api->call('server/power/status', ['uniq_id' => $uid]);
                $vars['lwPower'] = is_scalar($power['powerStatus'] ?? null)
                    ? liquidweb_displayValue('power', (string) $power['powerStatus']) : 'Unavailable';
            } catch (\Throwable $e) {
                $vars['lwPower'] = 'Unavailable';
            }
            $vars['lwUpdated'] = gmdate('Y-m-d H:i:s') . ' UTC';
            // Short session-local cache; no tables, cron or public cache files.
            if (session_status() === PHP_SESSION_ACTIVE) {
                foreach (($_SESSION['liquidweb'] ?? []) as $oldKey => $entry) {
                    if (($entry['expires'] ?? 0) <= time()) {
                        unset($_SESSION['liquidweb'][$oldKey]);
                    }
                }
                $_SESSION['liquidweb'][$key] = ['expires' => time() + 60, 'vars' => $vars];
            }
        }
    } catch (\Throwable $e) {
        // Safe client message: configuration and upstream errors remain private.
        $vars['lwError'] = 'Unable to retrieve server information. Please contact support if the problem persists.';
    }
    $vars['lwNetwork'] = [];
    $vars['lwNetworkError'] = '';
    $vars['lwNetworkUrl'] = 'clientarea.php?action=productdetails&id=' . (int) $params['serviceid']
        . '&lw_network=1';
    // Do not append a fragment: client themes may interpret it as an active tab.
    // Read-only request through WHMCS's existing authorized service page.
    // Fetch graphs only when requested, so normal visits keep their original cost.
    if ($vars['lwError'] === '' && ($_GET['lw_network'] ?? '') === '1') {
        $networkKey = 'en020b1-' . $key;
        $cachedNetwork = $_SESSION['liquidweb_network'][$networkKey] ?? null;
        if (is_array($cachedNetwork) && ($cachedNetwork['expires'] ?? 0) > time()) {
            $vars['lwNetwork'] = $cachedNetwork['network'];
            $vars['lwNetworkError'] = $cachedNetwork['error'];
        } else {
            try {
                $api = new \Duplika\LiquidWeb\Api($username, $password);
                $response = $api->call('monitoring/bandwidth/graph', [
                    'uniq_id' => $uid, 'frequency' => 'daily', 'width' => 800, 'height' => 220,
                ]);
                $vars['lwNetwork'] = \Duplika\LiquidWeb\Network::render($response);
                $vars['lwNetwork']['updated'] = gmdate('Y-m-d H:i:s') . ' UTC';
            } catch (\Throwable $e) {
                $vars['lwNetworkError'] = 'Network history is currently unavailable.';
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                foreach (($_SESSION['liquidweb_network'] ?? []) as $oldKey => $entry) {
                    if (($entry['expires'] ?? 0) <= time()) {
                        unset($_SESSION['liquidweb_network'][$oldKey]);
                    }
                }
                $_SESSION['liquidweb_network'][$networkKey] = [
                    'expires' => time() + ($vars['lwNetworkError'] === '' ? 300 : 30),
                    'network' => $vars['lwNetwork'], 'error' => $vars['lwNetworkError'],
                ];
            }
        }
    }
    return ['templatefile' => 'clientarea', 'vars' => $vars];
}

function liquidweb_AdminServicesTabFields(array $params): array
{
    try {
        $uid = liquidweb_assetId($params);
        return ['Liquid Web' => 'UID ' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . ' · Module 0.2.0-beta.1 · By Duplika (duplika.com)'];
    } catch (\Throwable $e) {
        return ['Liquid Web' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
    }
}

/** Native administrator buttons and WHMCS billing automation share these handlers. */
function liquidweb_SuspendAccount(array $params): string
{
    return liquidweb_firewallCommand($params, true);
}

function liquidweb_UnsuspendAccount(array $params): string
{
    return liquidweb_firewallCommand($params, false);
}

function liquidweb_firewallCommand(array $params, bool $suspend): string
{
    if (!in_array($params['configoption1'] ?? '', ['on', '1', 1, true], true)) {
        return 'Enable Firewall suspension in this product Module Settings first.';
    }
    try {
        foreach (['serviceid', 'userid', 'pid', 'serverid'] as $key) {
            if ((int) ($params[$key] ?? 0) < 1) {
                throw new \RuntimeException('A valid service and assigned server connection are required.');
            }
        }
        $uid = liquidweb_assetId($params);
        $api = new \Duplika\LiquidWeb\Api((string) ($params['serverusername'] ?? ''), (string) ($params['serverpassword'] ?? ''));
        \Duplika\LiquidWeb\State::command($params, $uid, $suspend, $api);
        unset($_SESSION['liquidweb'], $_SESSION['liquidweb_network']);
        return 'success';
    } catch (\Throwable $e) {
        // No raw API payloads or database exception messages reach clients/logs.
        if ($e instanceof \RuntimeException && !($e instanceof \PDOException)
            && !($e instanceof \Illuminate\Database\QueryException)) {
            return $e->getMessage();
        }
        return 'Unable to store or read the firewall snapshot. No success was reported. Check database access and retry; do not delete snapshots.';
    }
}

// Provisioning, termination, reboot and password changes remain unsupported.
