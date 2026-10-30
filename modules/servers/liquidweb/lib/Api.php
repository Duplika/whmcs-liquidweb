<?php
/** @author Duplika https://duplika.com/ | @copyright 2026 Duplika | @license MIT */
namespace Duplika\LiquidWeb;

/** Read-only Liquid Web API client with explicit v3/v2 routing. No legacy Storm dependency. */
final class Api
{
    private $username;
    private $password;

    public function __construct(string $username, string $password)
    {
        if ($username === '' || $password === '') {
            throw new \RuntimeException('Configure the Liquid Web API credentials in the WHMCS server connection.');
        }
        $this->username = $username;
        $this->password = $password;
    }

    public function call(string $method, array $params): array
    {
        // An allowlist prevents accidental provisioning or power mutations.
        $routes = [
            'asset/list' => 'v3',
            'asset/details' => 'v3',
            'server/power/status' => 'v3',
            'monitoring/bandwidth/graph' => 'v2',
        ];
        if (!isset($routes[$method])) {
            throw new \InvalidArgumentException('Operation not allowed.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP cURL extension is required.');
        }
        $handle = curl_init('https://api.liquidweb.com/' . $routes[$method] . '/' . $method);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['params' => $params], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        // Never log credentials, raw responses, customer data, or provider errors.
        if ($body === false) {
            throw new \RuntimeException('Unable to connect to Liquid Web.');
        }
        if ($status === 401) {
            throw new \RuntimeException('Liquid Web returned HTTP 401. Check the API credentials.');
        }
        if ($status === 403) {
            throw new \RuntimeException('Liquid Web returned HTTP 403. Access denied to ' . $method . '.');
        }
        if ($status !== 200) {
            throw new \RuntimeException('Liquid Web returned HTTP ' . $status . '.');
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Liquid Web returned an invalid response.');
        }
        if (!is_array($data) || isset($data['error_class']) || !empty($data['error'])) {
            throw new \RuntimeException('Liquid Web could not complete the request. Check permissions and the service assignment.');
        }
        return $data;
    }
}
