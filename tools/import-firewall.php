<?php
/** One-time CLI migration only. Not included in the installation ZIP.
 * @author Duplika https://duplika.com/ | @copyright 2026 Duplika | @license MIT
 * Usage: php import-firewall.php /path/to/whmcs ABC123 '{"type":"none"}'
 * Supply the KNOWN previous config. No credentials are accepted in arguments.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 4 || !preg_match('/^[A-HJ-NP-Z0-9]{6}$/D', $argv[2])) {
    fwrite(STDERR, "Usage: php import-firewall.php WHMCS_ROOT UID ORIGINAL_CONFIG_JSON\n"); exit(1);
}
$root = realpath($argv[1]);
if (!$root || !is_file($root . '/init.php') || !is_file($root . '/modules/servers/liquidweb/lib/State.php')) {
    fwrite(STDERR, "WHMCS root or updated Liquid Web module was not found.\n"); exit(1);
}
require $root . '/init.php';
require_once $root . '/modules/servers/liquidweb/liquidweb.php';
use WHMCS\Database\Capsule;
use Duplika\LiquidWeb\Api;
use Duplika\LiquidWeb\State;
try {
    $original = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($original)) { throw new RuntimeException('Supply a JSON firewall configuration.'); }
    $uid = $argv[2];
    $matches = Capsule::table('tblhosting as h')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->join('tblservers as s', 's.id', '=', 'h.server')
        ->join('tblcustomfields as f', function ($join) {
            $join->on('f.relid', '=', 'p.id')->where('f.type', '=', 'product')
                ->where('f.fieldname', '=', 'LiquidWeb UID');
        })
        ->join('tblcustomfieldsvalues as v', function ($join) {
            $join->on('v.fieldid', '=', 'f.id')->on('v.relid', '=', 'h.id');
        })
        ->where('p.servertype', 'liquidweb')->where('s.type', 'liquidweb')
        ->where('v.value', $uid)->whereIn('h.domainstatus', ['Active', 'Suspended'])
        ->select('h.id', 'h.userid', 'h.packageid', 'h.server', 's.username', 's.password')->get();
    if (count($matches) !== 1) {
        throw new RuntimeException('Expected exactly one Active/Suspended Liquid Web service with this UID and server connection.');
    }
    $service = $matches[0];
    $params = ['serviceid' => (int) $service->id, 'userid' => (int) $service->userid,
        'pid' => (int) $service->packageid, 'serverid' => (int) $service->server,
        'serverusername' => (string) $service->username];
    if (liquidweb_assetId($params) !== $uid) { throw new RuntimeException('UID mismatch.'); }
    State::adopt($params, $uid, $original, new Api($service->username, decrypt($service->password)));
    echo 'Imported the known restore configuration for service #' . (int) $service->id . ".\n";
    echo "No firewall or WHMCS status was changed. Use native module commands from now on.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Import failed. No firewall or WHMCS status was changed. Check the service assignment, known config, existing snapshot and database access.\n");
    exit(1);
}
