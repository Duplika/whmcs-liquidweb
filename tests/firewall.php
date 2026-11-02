<?php
// Offline tests: no WHMCS, database, credentials or network access.
define('WHMCS', true);
require_once __DIR__ . '/../modules/servers/liquidweb/lib/Text.php';
require_once __DIR__ . '/../modules/servers/liquidweb/lib/Firewall.php';
require_once __DIR__ . '/../modules/servers/liquidweb/lib/Notice.php';
use Duplika\LiquidWeb\Firewall;
use Duplika\LiquidWeb\Notice;
function check($ok, $why) { if (!$ok) { throw new RuntimeException($why); } }
$uid = 'ABC123';
$basic = ['type' => 'basic', 'allow' => ['cpanel', 'http', 'https']];
$block = ['type' => 'advanced', 'rules' => []];
foreach ([$basic, ['type' => 'none']] as $original) {
    $remote = $original; $record = null; $writes = 0; $saves = [];
    $read = function () use (&$remote) { return ['system' => 'liquidweb'] + $remote; };
    $save = function ($value) use (&$record, &$saves) { $record = $value; $saves[] = $value; };
    $write = function ($value) use (&$remote, &$record, &$writes, $uid) {
        check($record !== null, 'Snapshot must exist before a remote mutation');
        check($value['uniq_id'] === $uid, 'Use assigned UID only');
        unset($value['uniq_id']); $remote = $value; $writes++;
        return ['modified' => $uid];
    };
    Firewall::run(true, $uid, $record, $read, $write, $save);
    check($remote === $block && $record['phase'] === 'blocked', 'Suspend verified');
    check($saves[0]['phase'] === 'prepared' && $record['original'] === $original, 'Durable original');
    Firewall::run(true, $uid, $record, $read, $write, $save);
    check($writes === 1 && $record['original'] === $original, 'Repeated suspend cannot overwrite backup');
    Firewall::run(false, $uid, $record, $read, $write, $save);
    check($remote === $original && $record['phase'] === 'restored', 'Exact restore');
    Firewall::run(false, $uid, $record, $read, $write, $save);
    check($writes === 2, 'Repeated restore must not mutate');
    Firewall::run(true, $uid, $record, $read, $write, $save);
    check($remote === $block && $writes === 3, 'A later suspension takes a new snapshot');
}
function mustFail(callable $operation) {
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Expected a failure');
}
$noWrite = function () { throw new LogicException('Unexpected write'); };
$noSave = function () { throw new LogicException('Unexpected save'); };
$readBlock = fn() => ['system' => 'liquidweb'] + $block;
mustFail(fn() => Firewall::run(true, $uid, null, $readBlock, $noWrite, $noSave));
mustFail(fn() => Firewall::run(false, $uid, null, $readBlock, $noWrite, $noSave));
foreach ([['system' => 'other', 'type' => 'none'], ['system' => 'liquidweb', 'type' => 'advanced', 'rules' => [['action' => 'allow']]], ['system' => 'liquidweb', 'type' => 'basic', 'allow' => 'http']] as $invalid) {
    mustFail(fn() => Firewall::configuration($invalid));
}
$record = ['phase' => 'blocked', 'original' => $basic];
$changed = fn() => ['system' => 'liquidweb', 'type' => 'basic', 'allow' => ['ssh']];
mustFail(fn() => Firewall::run(false, $uid, $record, $changed, $noWrite, $noSave));
mustFail(fn() => Firewall::run(true, $uid, $record, $changed, $noWrite, $noSave));
// A timeout after provider-side success must preserve the restore snapshot.
$remote = $basic; $record = null;
$read = function () use (&$remote) { return ['system' => 'liquidweb'] + $remote; };
$save = function ($value) use (&$record) { $record = $value; };
$timeout = function () use (&$remote, $block) { $remote = $block; throw new RuntimeException('Timeout'); };
mustFail(function () use ($uid, &$record, $read, $timeout, $save) { Firewall::run(true, $uid, $record, $read, $timeout, $save); });
check($record['phase'] === 'prepared' && $record['original'] === $basic, 'Retain prepared snapshot after timeout');
Firewall::run(true, $uid, $record, $read, $noWrite, $save);
check($record['phase'] === 'blocked', 'Retry confirms accepted timeout without another write');
// A successful HTTP reply alone does not prove the target was applied.
$record = null; $remote = $basic;
$ackOnly = fn() => ['modified' => $uid];
mustFail(function () use ($uid, &$record, $read, $ackOnly, $save) { Firewall::run(true, $uid, $record, $read, $ackOnly, $save); });
check($record['phase'] === 'prepared', 'Unverified writes cannot mark success');
// Database write failure must precede and prevent the first remote change.
$record = null;
mustFail(fn() => Firewall::run(true, $uid, null, $read, $noWrite, function () { throw new RuntimeException('DB failure'); }));
$billing = Notice::render(true, true, 42);
check(str_contains($billing, 'viewinvoice.php?id=42') && str_contains($billing, 'non-payment'), 'Owned overdue invoice CTA');
$manual = Notice::render(false, true, 42);
check(!str_contains($manual, 'viewinvoice') && !str_contains($manual, 'non-payment'), 'Manual reasons never claim debt');
check(!str_contains(Notice::render(true, false, 0), 'Cloud Firewall'), 'No unverified firewall claim');
check(!str_contains(Notice::render(true, true, 0), 'viewinvoice'), 'No invented invoice');
echo "Firewall retry, restoration, conflict and notice checks passed.\n";
