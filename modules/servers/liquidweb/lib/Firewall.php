<?php
/** @author Duplika https://duplika.com/ | @copyright 2026 Duplika | @license MIT */
namespace Duplika\LiquidWeb;

/** Small, retryable firewall state machine. Persistence precedes every first write. */
final class Firewall
{
    public static function configuration(array $data): array
    {
        if (($data['system'] ?? '') !== 'liquidweb') {
            throw new \RuntimeException('Unsupported firewall system. No change was made.');
        }
        if (($data['type'] ?? '') === 'none') {
            return ['type' => 'none'];
        }
        if (($data['type'] ?? '') === 'advanced' && ($data['rules'] ?? null) === []) {
            return ['type' => 'advanced', 'rules' => []];
        }
        if (($data['type'] ?? '') === 'basic' && is_array($data['allow'] ?? null)
            && array_is_list($data['allow']) && count($data['allow']) <= 100) {
            foreach ($data['allow'] as $service) {
                if (!is_string($service) || $service === '' || strlen($service) > 128) {
                    throw new \RuntimeException('Invalid basic firewall configuration.');
                }
            }
            $allow = array_values(array_unique($data['allow']));
            sort($allow, SORT_STRING);
            return ['type' => 'basic', 'allow' => $allow];
        }
        throw new \RuntimeException('Unsupported firewall configuration. Review it in Liquid Web.');
    }

    public static function run(bool $suspend, string $uid, ?array $record, callable $read,
        callable $write, callable $save): void
    {
        $blocked = ['type' => 'advanced', 'rules' => []];
        $current = self::configuration($read());
        if ($record !== null) {
            if (!in_array($record['phase'] ?? '', ['prepared', 'blocked', 'restored'], true)
                || !is_array($record['original'] ?? null)
                || !in_array($record['original']['type'] ?? '', ['none', 'basic'], true)
                || self::configuration(['system' => 'liquidweb'] + $record['original']) !== $record['original']) {
                throw new \RuntimeException('Invalid saved firewall state. Restore from a verified backup.');
            }
        }
        if ($suspend) {
            if ($record === null || $record['phase'] === 'restored') {
                if ($current === $blocked) {
                    throw new \RuntimeException('Firewall is already blocked without a pending restore snapshot. Restore it manually first; do not guess the original configuration.');
                }
                $record = ['phase' => 'prepared', 'original' => $current];
                $save($record); // Durable even if the API times out after accepting the write.
            }
            if ($current !== $blocked && $current !== $record['original']) {
                throw new \RuntimeException('Firewall changed outside the module. Review before suspending.');
            }
            $target = $blocked;
            $phase = 'blocked';
        } else {
            if ($record === null) {
                throw new \RuntimeException('No saved firewall configuration. Restore manually; automatic unsuspension is unavailable.');
            }
            $target = $record['original'];
            $phase = 'restored';
            if ($record['phase'] === 'restored' && $current !== $target) {
                throw new \RuntimeException('Firewall changed after restoration. Review it manually.');
            }
            if ($current !== $blocked && $current !== $target) {
                throw new \RuntimeException('Firewall changed outside the module. Review before restoring.');
            }
        }
        if ($current !== $target) {
            $response = $write(['uniq_id' => $uid] + $target);
            if (($response['modified'] ?? '') !== $uid) {
                throw new \RuntimeException('Firewall update was not acknowledged. Retry to reconcile its actual state.');
            }
            if (self::configuration($read()) !== $target) {
                throw new \RuntimeException('Firewall change is not yet confirmed. Retry the native module command.');
            }
        }
        $record['phase'] = $phase;
        $save($record);
    }
}
