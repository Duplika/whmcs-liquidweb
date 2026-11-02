# Liquid Web Server Module for WHMCS

A lightweight, server module by [Duplika](https://duplika.com/) for displaying Liquid Web server information in the WHMCS client area.

**0.2.1-beta.1 · English / Spanish client area · MIT License**

For providers who create and manage servers manually. This independent project is not an official Liquid Web or WHMCS integration.

## Features

- Server type, resource status, and provider-cached power status.
- Operating system, city, country, and region when returned by the API.
- Incoming/outgoing network history, loaded on demand with **View network traffic**.
- Responsive SVG chart generated locally, without extra JavaScript libraries.
- Native WHMCS Suspend/Unsuspend buttons and billing automation through the Cloud Firewall.
- One private snapshot table, created only on the first firewall command or explicit import. No extra cron, JavaScript libraries or runtime package dependencies.

Hostname and IP remain in the standard WHMCS service page rather than being repeated.

## Simple scope

**Provisioning and general server administration remain manual.** Suspension blocks network access with Advanced mode and an empty rule list; reactivation restores the saved Basic or Disabled configuration. It does not power off the server, stop its processes or cancel provider billing. No provisioning, termination, reboot, resizing, password changes, console access, or backups.

Native firewall suspension is available automatically to services using this product module, an assigned Liquid Web connection and a valid admin-only LiquidWeb UID. There is no per-product enable checkbox. Installing this version enables lifecycle capabilities for all such services; WHMCS global automation settings and service overrides decide when automatic commands run. Never offer these commands to clients through custom buttons.

CPU utilization, load average, RAM/disk utilization, allocated-capacity summaries, service-health indicators, and panel access are not implemented. Power status does not indicate application availability. A catalog of monitorable services is not a health check.

## Requirements

- WHMCS with provisioning-module support; the pilot uses WHMCS 9.1.
- PHP 8.1+, cURL and JSON, using a PHP version supported by your WHMCS installation.
- Outbound HTTPS to `api.liquidweb.com`, valid CA certificates, and Liquid Web API credentials.
- An active PHP session for caching. Your Content Security Policy must allow `data:` images for the SVG chart.

Basic details and network history were reported working in one WHMCS/Nexus pilot with an `SS.VPS` server. This beta is not certified for every WHMCS version, theme, account, or Liquid Web product. Test both supported client-area languages and optional metadata on a controlled service before wider use.

## Installation

1. Download the install ZIP from [Releases](https://github.com/Duplika/whmcs-liquidweb/releases), then upload `modules/servers/liquidweb/` to the matching WHMCS directory.
2. In **System Settings → Servers**, add a connection:

   | Field | Value |
   | --- | --- |
   | Name | Liquid Web |
   | Hostname | api.liquidweb.com |
   | Module / Type | Liquid Web |
   | Username | Liquid Web API username |
   | Password | The matching API token, without a Bearer prefix |

   The API destination is fixed HTTPS; hostname, port and SSL checkbox values do not change it. Do not enter VPS root credentials. Save, reopen, then use **Test Connection**. A Technician token worked in the pilot; verify minimum permissions for your account.
3. In the product's **Module Settings**, select **Liquid Web** and **Do not automatically setup this product**. Review suspension/termination automation before switching existing products.
4. Add a product custom field named exactly **LiquidWeb UID**, type **Text Box**, with **Admin Only** enabled. Do not display it on the order form or allow client editing.
5. Assign the connection to each client service and fill its six-character Liquid Web UID. Use the asset UID, not the hostname.
6. Open the service in the client area and click **View network traffic**.

One API connection can serve multiple services in the same Liquid Web account; each service has its own UID. Existing orders/services can be retained. Changing a product module affects every service using that product, so start with a pilot.

**Do not install alongside the old official LiquidWeb module:** PHP function names are case-insensitive and can collide.

## Native suspension and payment reactivation

1. Back up WHMCS and update the complete module directory. Save a Liquid Web product's Module Settings again so WHMCS discovers the new module `hooks.php` file; clear the WHMCS template cache if necessary.
2. Keep **Do not automatically setup this product** selected: server creation remains manual. No extra module option is required for suspension. A service must use the Liquid Web product module, its assigned Liquid Web server connection and a valid admin-only UID. Selecting a connection alone does not replace the product module assignment.
3. In the service administrator page, use WHMCS's native **Module Commands → Suspend / Unsuspend**. No extra dashboard or custom buttons are required. Use the native suspension action, rather than just editing the service status.
4. In **System Settings → Automation Settings**, enable **Enable Suspension** and **Enable Unsuspension**, and review **Suspend Days**. The existing WHMCS system cron handles overdue suspension; WHMCS handles reactivation after qualifying invoice payment. These are global settings: review other products before changing them. This module does not change them for you.
5. WHMCS identifies billing suspensions by the reason **Overdue on Payment**. A manual custom reason (for example, abuse or identity review) must not trigger automatic reactivation after payment. Use the native billing reason for manual non-payment suspensions; verify the stored reason after the command. Follow WHMCS's normal billing/date rules; no payment hooks or parallel automation are added.

The module saves the original firewall before sending an update, sets `type=advanced` with `rules=[]`, then reads the firewall back before reporting success. On Unsuspend, it restores the exact saved Basic service allow-list or Disabled mode and verifies it again. It never defaults to Disabled when a snapshot is missing.

For the first release of this feature, pre-existing Advanced configurations (with or without rules), saved presets and other firewall systems require manual handling. A VPS already blocked manually has no module snapshot. If its previous Basic/Disabled configuration is known, use the one-time CLI import below; otherwise restore it manually from verified records. Do not guess a default or re-run Suspend expecting to recover unknown settings.

A private `mod_liquidweb_firewall` table stores one record per service: the original configuration, operation phase and assignment identifiers. The database account needs CREATE permission for the first command/import and normal SELECT/INSERT/UPDATE access thereafter. MySQL named locks prevent simultaneous module operations on the same service/asset. Snapshots are bound to the service, owner, product, connection, API username and UID; changing that assignment requires manual review. Do not map the same asset to multiple WHMCS services.

Repeated commands preserve the original snapshot. A timeout or delayed provider update returns an error and retains the snapshot: retry the same native command to reconcile the actual state. If someone changes the firewall outside the module, restoration stops for review rather than overwriting those changes. Review WHMCS's Module Queue and Activity Log for failed operations. Do not delete snapshots to bypass an error.

### Import a manually suspended server once

Only use this when the previous configuration is independently known. The optional [tools/import-firewall.php](tools/import-firewall.php) CLI tool takes the WHMCS root, asset UID and known original JSON. For example:

```bash
php /path/to/import-firewall.php /path/to/whmcs ABC123 '{"type":"none"}'
# For a known Basic configuration, supply its exact original allow-list:
php /path/to/import-firewall.php /path/to/whmcs ABC123 '{"type":"basic","allow":["http","https"]}'
```

These are alternative examples, not commands to run consecutively. Download the tool from the same release tag as the installed module and keep it outside the web root. It uses the saved WHMCS credentials, requires exactly one mapped Active/Suspended service, verifies that the remote firewall is currently Advanced with no rules, and imports only the supplied known original configuration. It refuses to overwrite any existing snapshot. The tool makes no firewall changes and does not change WHMCS status or suspension reason. Remove it after use.

Then reconcile the service using the native Suspend command if necessary. For non-payment, verify WHMCS stores **Overdue on Payment** as the reason so payment reactivation applies. The next native Unsuspend can restore the imported configuration. No migration script runs automatically, and no client/asset details are embedded in the release.

### Client suspension notice

A native `ClientAreaProductDetailsOutput` hook displays a localized notice on suspended Liquid Web services without Liquid Web API requests. It identifies non-payment only from WHMCS's exact billing suspension reason. A Cloud Firewall explanation appears only with a confirmed module snapshot for the current assignment. The payment button links only to an unpaid, due Hosting invoice belonging to that service's owner. If several invoices remain unpaid, WHMCS's normal reactivation rules still apply. No arbitrary suspension reason, saved firewall data or credentials are exposed.

The module skips provider metadata and graphs while the service is suspended. Notices depend on the theme rendering WHMCS's native product-details hook; test this on your theme, particularly for suspended services.

### Monitoring remains manual

Firewall changes do not pause Liquid Web monitoring. Current pilot discovery returned no `monitoring/services/update` method in v2 or v3; v2 exposed only Get/Status. Pause appropriate monitoring in Liquid Web before suspension and restore only the previously enabled checks after reactivation. Until Liquid Web provides a supported control, unattended firewall suspension can generate monitoring alerts or a support response. The module does not claim to suppress those alerts.

### Pilot checklist

Use a controlled service before relying on unattended billing automation:

- Confirm Basic/Disabled restore settings and save a separate copy in your normal administration records.
- Pause Liquid Web monitoring manually, then use native Suspend. Confirm WHMCS becomes Suspended, the provider firewall is Advanced with no rules, external access is blocked, and the notice appears in the client area.
- Retry Suspend and verify the saved original is unchanged. Use native Unsuspend; verify the original firewall and external access return, and WHMCS becomes Active.
- Test a genuinely overdue pilot invoice and subsequent payment using your WHMCS billing workflow. Confirm the billing reason, invoice link and automatic reactivation. A payment against a suspension for another reason must not reopen access.
- Re-enable only the monitoring that was enabled before suspension.

The direct API block operation was verified on one pilot VPS. The new native WHMCS lifecycle, hooks, MySQL persistence and end-to-end payment flow still need this installation-level pilot.

## Client-area languages

The module follows WHMCS's active language through the native `Lang::trans('locale')` value. Spanish locales use the bundled Spanish catalog; English and unsupported locales fall back to English. Use WHMCS's existing language selector and language preferences; there is no module selector, cookie, browser-language detection or extra provider request.

Translations cover server field labels, known resource/power states, the United States country name, network buttons and errors, chart legends/accessibility text, and suspension/payment notices. Provider-specific identifiers, unknown product/status codes, operating system names and region labels remain unchanged. The administrator diagnostics and README remain in English. Dates and chart times remain explicitly UTC.

As this is a provisioning module, the module-owned `lang/english.php` and `lang/spanish.php` catalogs are loaded by a small adapter using the native active locale. Native WHMCS overrides take priority through `Lang::trans('liquidweb.KEY')`. To customize a phrase, append the relevant key to your existing `lang/overrides/spanish.php`, without replacing that file, for example:

```php
$_LANG['liquidweb']['serverInformation'] = 'Información del servidor';
$_LANG['liquidweb']['viewNetwork'] = 'Ver tráfico de red';
```

Supported keys are listed in the module catalogs. No core language files or theme files are changed by installing the module. Session cache keys include the active locale so a language switch cannot reuse a graph or status labels rendered for another language. Overrides may take up to the normal cache lifetime to appear in cached data.

## API compatibility

| Version | Endpoint | Purpose |
| --- | --- | --- |
| v3 | `asset/list` | Connection test, one asset |
| v3 | `asset/details` | Details, including region/distro |
| v3 | `server/power/status` | Cached power status |
| v2 | `monitoring/bandwidth/graph` | Daily network history |
| v2 | `network/firewall/details` | Read and verify firewall configuration during module commands |
| v2 | `network/firewall/update` | Native suspend/restore operation |

The observed network response contains JSON samples with Unix timestamps, incoming/outgoing points and the unit `bytes`. The chart shows **reported bytes per sample**, scaled to KiB/MiB/GiB. It does not infer bytes/second, daily totals, or billable usage. Times are UTC; missing data is not drawn as zero.

In pilot API discovery on October 30, 2026, load graph/statistics methods were listed in v2 but not v3. Their v2 calls returned empty objects for the tested SS.VPS. `v3/server/details` returned RecordNotFound for a UID recognized by `asset/details`. These are observations from one product/account, not proof that metrics are unavailable for every Liquid Web server.

### Feedback for Liquid Web

We would welcome documented v3 endpoints for SS.VPS capacity, load/RAM time series and service health, plus a v3 replacement for network history. Clear sample semantics, units, timestamps and retention would let integrations display these metrics consistently. The module uses v2 for network history and firewall operations; v3 for basic server information.

## Performance and security

- Basic details: two requests on a cache miss, cached for 60 seconds per session.
- Network history: one additional request on demand, cached five minutes; failures cached 30 seconds.
- Cache isolation includes owner, service, connection, UID and a credential fingerprint. No cross-session shared cache.
- Each request has a three-second connection timeout and an eight-second total timeout. Basic calls are sequential; requesting network data can add another eight seconds.
- Fixed API host, explicit read endpoint allowlist, one validated firewall write endpoint, TLS verification and no redirects.
- No logging of credentials or complete API responses. Only selected public fields reach the template; output is HTML-escaped.
- Uses WHMCS service authorization and the stored admin-only UID. No independent public HTTP handler.

## Updates and troubleshooting

Replace the full `modules/servers/liquidweb/` directory. Keep your connection and custom fields. If template changes are missing, use **Utilities → System → System Cleanup → Empty Template Cache**.

- **401:** check the saved API username/token pair. Save and reopen before testing.
- **403:** verify permissions for the requested operation.
- **Missing details:** verify the connection, UID and exact admin-only field name.
- **Missing chart:** verify available network samples and CSP support for data images.
- Avoid chart URL hash fragments: one caused the pilot theme to hide the main service content.

Before rolling back to an information-only version, restore all firewalls blocked by this version. Keep the snapshot table and a database backup until every pending restoration is complete. Replacing module files does not restore remote firewalls.

## Validation and issue reports

GitHub Actions checks PHP syntax and runs offline tests before publishing the install ZIP. These checks do not call Liquid Web or run WHMCS. Pilot feedback does not replace testing in your installation. To run the offline checks locally: `php tests/smoke.php`, `php tests/firewall.php` and `php tests/language.php`. The firewall tests cover retry safety, preservation/restoration of Basic and Disabled configurations, rejected conflicts, persistence failures, timeouts and billing notices. They do not validate MySQL or WHMCS integration.

Report issues with the module, WHMCS and PHP versions, theme, server type and error shown. Never include API tokens, passwords, full account responses or customer-identifying details.

The install ZIP contains only the module (including its hooks and libraries), README and LICENSE. Development tests/workflows live in the repository but are excluded from the install ZIP. The optional generic CLI import tool is available only in the repository. Customer-specific data is not published. No copy of the old Liquid Web plugin is bundled.

## License and credits

Copyright © 2026 [Duplika](https://duplika.com/). Released under the [MIT License](LICENSE). Liquid Web and WHMCS names and trademarks belong to their respective owners.

[Liquid Web API documentation](https://api.liquidweb.com/docs) · [WHMCS provisioning-module documentation](https://developers.whmcs.com/provisioning-modules/getting-started/)
