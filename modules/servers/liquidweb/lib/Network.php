<?php
/** @author Duplika https://duplika.com/ | @copyright 2026 Duplika | @license MIT */
namespace Duplika\LiquidWeb;

/** Local renderer. Remote strings and markup are never embedded in the SVG. */
final class Network
{
    public static function samples(array $response): array
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || count($data) > 2000) {
            throw new \RuntimeException('Invalid network time series.');
        }
        $result = [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_array($row['points'] ?? null)) {
                continue;
            }
            $time = $row['time'] ?? null;
            if ((!is_string($time) && !is_int($time)) || !preg_match('/^[0-9]{1,10}$/D', (string) $time)) {
                continue;
            }
            $time = (int) $time;
            if ($time < 946684800 || $time > 4102444800) {
                continue;
            }
            $sample = ['time' => $time, 'incoming' => null, 'outgoing' => null];
            foreach ($row['points'] as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $name = $point['name'] ?? null;
                $value = $point['value'] ?? null;
                if (!in_array($name, ['incoming', 'outgoing'], true) || ($point['unit'] ?? '') !== 'bytes') {
                    continue;
                }
                if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
                    continue;
                }
                $value = (float) $value;
                if (is_finite($value) && $value >= 0 && $value <= 1e18) {
                    $sample[$name] = $value;
                }
            }
            if ($sample['incoming'] !== null || $sample['outgoing'] !== null) {
                $result[$time] = $sample;
            }
        }
        ksort($result, SORT_NUMERIC);
        $result = array_values($result);
        if (count($result) < 2 || count($result) > 600) {
            throw new \RuntimeException('Insufficient network samples or the time series exceeds the supported size.');
        }
        return $result;
    }

    private static function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    public static function render(array $response): array
    {
        $samples = self::samples($response);
        $first = $samples[0]['time'];
        $last = $samples[count($samples) - 1]['time'];
        $deltas = [];
        $maximum = 0.0;
        foreach ($samples as $index => $sample) {
            $maximum = max($maximum, $sample['incoming'] ?? 0, $sample['outgoing'] ?? 0);
            if ($index > 0) {
                $deltas[] = $sample['time'] - $samples[$index - 1]['time'];
            }
        }
        sort($deltas, SORT_NUMERIC);
        $interval = $deltas[(int) floor(count($deltas) / 2)];
        $factor = 1.0;
        $unit = 'B';
        foreach ([['KiB', 1024], ['MiB', 1048576], ['GiB', 1073741824]] as $scale) {
            if ($maximum >= $scale[1]) {
                $unit = $scale[0];
                $factor = (float) $scale[1];
            }
        }
        $ceiling = max(1.0, $maximum * 1.1);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 300" role="img">'
            . '<title>Incoming and outgoing traffic</title>'
            . '<desc>Reported bytes per sample. The horizontal axis uses UTC. Values are not a per-second rate.</desc>'
            . '<rect width="900" height="300" fill="#ffffff"/>'
            . '<g font-family="system-ui,sans-serif" font-size="12" fill="#344054">';
        $svg .= '<text x="64" y="18">' . $unit . ' / sample</text>';
        for ($tick = 0; $tick <= 4; $tick++) {
            $y = 230 - $tick * 50;
            $svg .= '<path d="M64 ' . $y . 'H876" stroke="#e4e7ec"/>'
                . '<text x="56" y="' . ($y + 4) . '" text-anchor="end">'
                . self::number($ceiling * $tick / 4 / $factor) . '</text>';
        }
        for ($tick = 0; $tick <= 4; $tick++) {
            $time = (int) round($first + ($last - $first) * $tick / 4);
            $x = 64 + 812 * $tick / 4;
            $anchor = $tick === 0 ? 'start' : ($tick === 4 ? 'end' : 'middle');
            $svg .= '<text x="' . self::number($x) . '" y="254" text-anchor="' . $anchor . '">'
                . gmdate('d/m H:i', $time) . '</text>';
        }
        foreach (['incoming' => '#1675d1', 'outgoing' => '#00875a'] as $name => $color) {
            $path = '';
            $previous = null;
            foreach ($samples as $sample) {
                if ($sample[$name] === null) {
                    $previous = null;
                    continue;
                }
                $x = 64 + ($sample['time'] - $first) / ($last - $first) * 812;
                $y = 230 - $sample[$name] / $ceiling * 200;
                $command = $previous === null || ($sample['time'] - $previous) > $interval * 1.5 ? 'M' : 'L';
                $path .= $command . self::number($x) . ' ' . self::number($y) . ' ';
                $previous = $sample['time'];
            }
            $svg .= '<path d="' . $path . '" fill="none" stroke="' . $color . '" stroke-width="2"/>';
        }
        $svg .= '<text x="64" y="284" fill="#1675d1">Incoming</text>'
            . '<text x="170" y="284" fill="#00875a">Outgoing</text></g></svg>';
        return [
            'image' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'period' => gmdate('d/m/Y H:i', $first) . ' – ' . gmdate('d/m/Y H:i', $last) . ' UTC',
            'count' => count($samples),
            'interval' => $interval,
        ];
    }
}
