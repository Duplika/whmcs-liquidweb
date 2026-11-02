<?php
/** @author Duplika https://duplika.com/ | @copyright 2026 Duplika | @license MIT */
namespace Duplika\LiquidWeb;
use WHMCS\Database\Capsule;

final class Notice
{
    /** Called only for WHMCS's already-authorized service object, never a request ID. */
    public static function forService(int $id): string
    {
        $service = Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.id', $id)->where('p.servertype', 'liquidweb')
            ->where('h.domainstatus', 'Suspended')
            ->select('h.id', 'h.userid', 'h.packageid', 'h.server', 'h.suspendreason')->first();
        if (!$service) { return ''; }
        $blocked = false;
        $row = State::get($id);
        if ($row) {
            $record = json_decode($row->snapshot, true);
            $binding = json_decode($row->binding, true);
            // Re-check assignment locally; never claim a block for a remapped asset.
            $serverUser = Capsule::table('tblservers')->where('id', $service->server)->value('username');
            $uid = \liquidweb_assetId(['pid' => $service->packageid, 'serviceid' => $id]);
            $expected = State::binding(['serviceid' => $id, 'userid' => $service->userid,
                'pid' => $service->packageid, 'serverid' => $service->server,
                'serverusername' => $serverUser], $uid);
            $blocked = $binding === $expected && ($record['phase'] ?? '') === 'blocked';
        }
        // WHMCS billing automation owns this reason, including when payment arrives.
        $overdue = trim((string) $service->suspendreason) === 'Overdue on Payment';
        $invoice = 0;
        if ($overdue) {
            $invoice = (int) Capsule::table('tblinvoiceitems as item')
                ->join('tblinvoices as invoice', 'invoice.id', '=', 'item.invoiceid')
                ->where('item.type', 'Hosting')->where('item.relid', $id)
                ->where('item.userid', $service->userid)->where('invoice.userid', $service->userid)
                ->where('invoice.status', 'Unpaid')->where('invoice.total', '>', 0)
                ->where('invoice.duedate', '<=', date('Y-m-d'))
                ->orderBy('invoice.duedate')->orderBy('invoice.id')->value('invoice.id');
        }
        return self::render($overdue, $blocked, $invoice);
    }

    public static function render(bool $overdue, bool $blocked, int $invoice): string
    {
        $textFor = fn($key) => htmlspecialchars(Text::t($key), ENT_QUOTES, 'UTF-8');
        $title = $textFor($overdue ? 'suspendedPayment' : 'suspended');
        $text = $blocked ? $textFor('firewallBlocked') . ' ' : '';
        $text .= $textFor($overdue ? 'payToReactivate' : 'contactToReactivate');
        $button = $overdue && $invoice > 0
            ? '<p><a class="btn btn-primary" href="viewinvoice.php?id=' . $invoice . '">' . $textFor('viewInvoice') . '</a></p>' : '';
        return '<section class="alert alert-warning lw-suspension" role="status" style="text-align:left">'
            . '<h3>' . $title . '</h3><p>' . $text . '</p>' . $button . '</section>';
    }
}
