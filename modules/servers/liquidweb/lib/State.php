<?php
/** @author Duplika https://duplika.com/ | @copyright 2026 Duplika | @license MIT */
namespace Duplika\LiquidWeb;
use WHMCS\Database\Capsule;

/** One private row per service; no credentials, custom fields or scheduled jobs. */
final class State
{
    public const TABLE = 'mod_liquidweb_firewall';

    public static function install(): void
    {
        if (Capsule::schema()->hasTable(self::TABLE)) { return; }
        try {
            Capsule::schema()->create(self::TABLE, function ($table) {
                $table->unsignedInteger('service_id')->primary();
                $table->string('remote_key', 64)->unique();
                $table->text('binding');
                $table->text('snapshot');
            });
        } catch (\Throwable $e) {
            if (!Capsule::schema()->hasTable(self::TABLE)) { throw $e; }
        }
    }

    public static function binding(array $params, string $uid): array
    {
        return ['service' => (int) $params['serviceid'], 'owner' => (int) $params['userid'],
            'product' => (int) $params['pid'], 'server' => (int) $params['serverid'],
            'uid' => $uid, 'account' => hash('sha256', (string) $params['serverusername'])];
    }

    public static function get(int $service): ?object
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) { return null; }
        return Capsule::table(self::TABLE)->where('service_id', $service)->first();
    }

    public static function command(array $params, string $uid, bool $suspend, Api $api): void
    {
        // MySQL advisory locks span the snapshot commit and remote requests.
        // Hold both the service and remote-asset lock to serialize suspend/restore.
        $binding = self::binding($params, $uid);
        $remote = hash('sha256', $binding['account'] . ':' . $uid);
        $connection = Capsule::connection();
        $locks = [];
        try {
            foreach (['lw-service-' . $binding['service'], 'lw-' . substr($remote, 0, 60)] as $name) {
                $result = $connection->select('SELECT GET_LOCK(?, 0) AS acquired', [$name]);
                if ((int) ($result[0]->acquired ?? 0) !== 1) {
                    throw new \RuntimeException('Another firewall operation is running. Retry later.');
                }
                $locks[] = $name;
            }
            self::install();
            $row = self::get($binding['service']);
            $encoded = json_encode($binding, JSON_THROW_ON_ERROR);
            if ($row && $row->binding !== $encoded) {
                throw new \RuntimeException('Service assignment differs from the saved firewall snapshot. Review before changing the firewall.');
            }
            $other = Capsule::table(self::TABLE)->where('remote_key', $remote)
                ->where('service_id', '<>', $binding['service'])->exists();
            if ($other) { throw new \RuntimeException('This asset has a firewall snapshot attached to another service.'); }
            $record = $row ? json_decode($row->snapshot, true, 32, JSON_THROW_ON_ERROR) : null;
            if ($row && !is_array($record)) { throw new \RuntimeException('Invalid firewall snapshot.'); }
            Firewall::run($suspend, $uid, $record,
                fn() => $api->call('network/firewall/details', ['uniq_id' => $uid]),
                fn($payload) => $api->updateFirewall($payload),
                function ($state) use ($binding, $remote, $encoded) {
                    Capsule::table(self::TABLE)->updateOrInsert(['service_id' => $binding['service']],
                        ['remote_key' => $remote, 'binding' => $encoded,
                            'snapshot' => json_encode($state, JSON_THROW_ON_ERROR)]);
                });
        } finally {
            foreach (array_reverse($locks) as $name) {
                $connection->select('SELECT RELEASE_LOCK(?)', [$name]);
            }
        }
    }
}
