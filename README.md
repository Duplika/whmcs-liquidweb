# Liquid Web Server Module for WHMCS

A lightweight, read-only server module by [Duplika](https://duplika.com/) for displaying Liquid Web server information in the WHMCS client area.

**0.1.7-beta.1 · English · MIT License**

For providers who create and manage servers manually. This independent project is not an official Liquid Web or WHMCS integration.

## Features

- Server type, resource status, and provider-cached power status.
- Operating system, city, country, and region when returned by the API.
- Incoming/outgoing network history, loaded on demand with **View network traffic**.
- Responsive SVG chart generated locally, without extra JavaScript libraries.
- No new database tables, cron jobs, package dependencies, or build step.

Hostname and IP remain in the standard WHMCS service page rather than being repeated.

## Simple scope

**Read-only: all server administration remains manual.** No provisioning, suspension, termination, reboot, resizing, password changes, console access, or backups.

CPU utilization, load average, RAM/disk utilization, allocated-capacity summaries, service-health indicators, and panel access are not implemented. Power status does not indicate application availability. A catalog of monitorable services is not a health check.

## Requirements

- WHMCS with provisioning-module support; the pilot uses WHMCS 9.1.
- PHP 8.1+, cURL and JSON, using a PHP version supported by your WHMCS installation.
- Outbound HTTPS to `api.liquidweb.com`, valid CA certificates, and Liquid Web API credentials.
- An active PHP session for caching. Your Content Security Policy must allow `data:` images for the SVG chart.

Basic details and network history were reported working in one WHMCS/Nexus pilot with an `SS.VPS` server. This beta is not certified for every WHMCS version, theme, account, or Liquid Web product. Test the English package and optional metadata on a controlled service before wider use.

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

## API compatibility

| Version | Endpoint | Purpose |
| --- | --- | --- |
| v3 | `asset/list` | Connection test, one asset |
| v3 | `asset/details` | Details, including region/distro |
| v3 | `server/power/status` | Cached power status |
| v2 | `monitoring/bandwidth/graph` | Daily network history |

The observed network response contains JSON samples with Unix timestamps, incoming/outgoing points and the unit `bytes`. The chart shows **reported bytes per sample**, scaled to KiB/MiB/GiB. It does not infer bytes/second, daily totals, or billable usage. Times are UTC; missing data is not drawn as zero.

In pilot API discovery on October 30, 2026, load graph/statistics methods were listed in v2 but not v3. Their v2 calls returned empty objects for the tested SS.VPS. `v3/server/details` returned RecordNotFound for a UID recognized by `asset/details`. These are observations from one product/account, not proof that metrics are unavailable for every Liquid Web server.

### Feedback for Liquid Web

We would welcome documented v3 endpoints for SS.VPS capacity, load/RAM time series and service health, plus a v3 replacement for network history. Clear sample semantics, units, timestamps and retention would let integrations display these metrics consistently. The module currently uses v2 only for network history.

## Performance and security

- Basic details: two requests on a cache miss, cached for 60 seconds per session.
- Network history: one additional request on demand, cached five minutes; failures cached 30 seconds.
- Cache isolation includes owner, service, connection, UID and a credential fingerprint. No cross-session shared cache.
- Each request has a three-second connection timeout and an eight-second total timeout. Basic calls are sequential; requesting network data can add another eight seconds.
- Fixed API host, read-only endpoint allowlist, TLS verification and no redirects.
- No logging of credentials or complete API responses. Only selected public fields reach the template; output is HTML-escaped.
- Uses WHMCS service authorization and the stored admin-only UID. No independent public HTTP handler.

## Updates and troubleshooting

Replace the full `modules/servers/liquidweb/` directory. Keep your connection and custom fields. If template changes are missing, use **Utilities → System → System Cleanup → Empty Template Cache**.

- **401:** check the saved API username/token pair. Save and reopen before testing.
- **403:** verify permissions for the requested operation.
- **Missing details:** verify the connection, UID and exact admin-only field name.
- **Missing chart:** verify available network samples and CSP support for data images.
- Avoid chart URL hash fragments: one caused the pilot theme to hide the main service content.

Restore the previous module directory to roll back. No remote server changes are made.

## Validation and issue reports

GitHub Actions checks PHP syntax and runs offline tests before publishing the install ZIP. These checks do not call Liquid Web or run WHMCS. Pilot feedback does not replace testing in your installation. To run the offline checks locally: `php tests/smoke.php`.

Report issues with the module, WHMCS and PHP versions, theme, server type and error shown. Never include API tokens, passwords, full account responses or customer-identifying details.

The release contains only the module, README and LICENSE. Diagnostic tools, development tests and customer-specific fixtures are excluded. No copy of the old Liquid Web plugin is bundled.

## License and credits

Copyright © 2026 [Duplika](https://duplika.com/). Released under the [MIT License](LICENSE). Liquid Web and WHMCS names and trademarks belong to their respective owners.

[Liquid Web API documentation](https://api.liquidweb.com/docs) · [WHMCS provisioning-module documentation](https://developers.whmcs.com/provisioning-modules/getting-started/)
