<?php
// Offline stand-in for WHMCS's native Lang facade. No network/database access.
final class Lang
{
    public static $locale = 'en_GB';
    public static $overrides = [];
    public static function trans($key) {
        return $key === 'locale' ? self::$locale : (self::$overrides[$key] ?? $key);
    }
}
define('WHMCS', true);
require __DIR__ . '/../modules/servers/liquidweb/liquidweb.php';
require __DIR__ . '/../modules/servers/liquidweb/lib/Notice.php';
use Duplika\LiquidWeb\Text;
use Duplika\LiquidWeb\Network;
use Duplika\LiquidWeb\Notice;
function check($ok, $why) { if (!$ok) { throw new RuntimeException($why); } }
$english = require __DIR__ . '/../modules/servers/liquidweb/lang/english.php';
$spanish = require __DIR__ . '/../modules/servers/liquidweb/lang/spanish.php';
check(array_keys($english) === array_keys($spanish), 'Both catalogs must have the same keys');
foreach ($spanish as $key => $value) { check(is_string($value) && $value !== '', 'Missing Spanish string: ' . $key); }
check(Text::t('serverInformation') === 'Server information', 'Native English locale');
Lang::$locale = 'es_ES';
check(Text::t('serverInformation') === 'Información del servidor', 'Native Spanish locale');
check(liquidweb_displayValue('status', 'Active') === 'Activo', 'Translate provider status');
check(liquidweb_displayValue('power', 'on') === 'Encendido', 'Translate power status');
check(liquidweb_displayValue('type', 'SS.VM') === 'SS.VM', 'Unknown product codes are not guessed');
check(liquidweb_displayValue('status', 'future-status') === 'future-status', 'Unknown state preserved');
$details = liquidweb_publicDetails(['type' => 'SS.VPS', 'distro' => 'alma', 'region' => ['city' => 'Lansing', 'country' => 'US', 'name' => 'US Central']]);
check($details['Ubicación'] === 'Lansing, Estados Unidos', 'Translate country and field label');
check($details['Región'] === 'US Central' && $details['Sistema operativo'] === 'AlmaLinux', 'Preserve provider region and OS');
$notice = Notice::render(true, true, 42);
check(str_contains($notice, 'Servicio suspendido por falta de pago') && str_contains($notice, 'Ver factura pendiente'), 'Translate billing notice and CTA');
check(!str_contains(Notice::render(false, true, 42), 'falta de pago'), 'Translation must not change suspension reason logic');
$fixture = ['data' => [
    ['time' => 1700000000, 'points' => [['name' => 'incoming', 'unit' => 'bytes', 'value' => 2048]]],
    ['time' => 1700000300, 'points' => [['name' => 'outgoing', 'unit' => 'bytes', 'value' => 4096]]],
]];
$chartEs = Network::render($fixture);
$svgEs = base64_decode(substr($chartEs['image'], strlen('data:image/svg+xml;base64,')));
check(str_contains($svgEs, 'Entrante') && str_contains($svgEs, 'Saliente') && str_contains($svgEs, 'KiB / muestra'), 'Translate SVG legend and units');
Lang::$locale = 'en_GB';
$chartEn = Network::render($fixture);
check($chartEs['image'] !== $chartEn['image'] && $chartEs['period'] === $chartEn['period'], 'Changing native language updates text without changing the data period');
check(Text::t('serverInformation') === 'Server information', 'Switching back does not retain Spanish');
Lang::$locale = 'es_AR';
check(Text::t('serverInformation') === 'Información del servidor', 'Spanish regional locale');
Lang::$locale = '../../spanish';
check(Text::t('serverInformation') === 'Server information', 'No untrusted locale file paths');
Lang::$locale = 'fr_FR';
check(Text::t('serverInformation') === 'Server information', 'Unsupported locales fall back to English');
Lang::$overrides['liquidweb.suspended'] = '<script>test</script>';
check(!str_contains(Notice::render(false, false, 0), '<script>'), 'Escape native HTML overrides in notices');
Lang::$overrides['liquidweb.incoming'] = '<script>test</script>';
$svg = base64_decode(substr(Network::render($fixture)['image'], strlen('data:image/svg+xml;base64,')));
check(!str_contains($svg, '<script>') && str_contains($svg, '&lt;script&gt;'), 'Escape native overrides inside SVG');
Lang::$overrides['liquidweb.serverInformation'] = 'Custom heading';
check(Text::t('serverInformation') === 'Custom heading', 'Native WHMCS overrides take priority');
echo "Native locale, translations, language switching, fallback and escaping checks passed.\n";
