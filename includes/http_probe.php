<?php
declare(strict_types=1);

function httpProbeSettings(): array
{
    $config = appConfig();
    $curl = $config['curl'] ?? [];
    $checks = $config['checks'] ?? [];

    $challengeCodes = $checks['challenge_status_codes'] ?? [403, 429, 503];
    if (!is_array($challengeCodes)) {
        $challengeCodes = [403, 429, 503];
    }

    return [
        'timeout' => max(1, (int) ($curl['timeout'] ?? 10)),
        'connect_timeout' => max(1, (int) ($curl['connect_timeout'] ?? 10)),
        'user_agent' => (string) ($curl['user_agent'] ?? ''),
        'accept_language' => (string) ($curl['accept_language'] ?? 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7'),
        'retry_with_get' => (bool) ($checks['retry_with_get'] ?? true),
        'get_body_limit_bytes' => max(1024, (int) ($checks['get_body_limit_bytes'] ?? 8192)),
        'availability_mode' => strtolower((string) ($checks['availability_mode'] ?? 'primary')),
        'challenge_status_codes' => array_values(array_unique(array_map('intval', $challengeCodes))),
    ];
}

function isHttpStatusAvailable(?int $statusCode): bool
{
    return $statusCode !== null && $statusCode >= 200 && $statusCode < 400;
}

function isChallengeHttpStatus(?int $statusCode, array $challengeCodes): bool
{
    return $statusCode !== null && in_array($statusCode, $challengeCodes, true);
}

function shouldRetryHttpProbe(array $result, array $settings): bool
{
    if (!$settings['retry_with_get']) {
        return false;
    }

    if (($result['probe_method'] ?? '') === 'GET') {
        return false;
    }

    if ((int) ($result['curl_error'] ?? 0) !== 0) {
        return true;
    }

    if (!isHttpStatusAvailable($result['status_code'] ?? null)) {
        return true;
    }

    return false;
}

function executeHttpProbe(string $url, array $settings, string $method): array
{
    $method = strtoupper($method) === 'GET' ? 'GET' : 'HEAD';
    $ch = curl_init($url);

    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: ' . $settings['accept_language'],
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $settings['timeout'],
        CURLOPT_CONNECTTIMEOUT => $settings['connect_timeout'],
        CURLOPT_USERAGENT => $settings['user_agent'] !== ''
            ? $settings['user_agent']
            : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($method === 'HEAD') {
        $options[CURLOPT_NOBODY] = true;
    } else {
        $options[CURLOPT_HTTPGET] = true;
        $maxBytes = (int) $settings['get_body_limit_bytes'];
        $received = 0;
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$received, $maxBytes) {
            $received += strlen($chunk);
            if ($received >= $maxBytes) {
                return 0;
            }
            return strlen($chunk);
        };
    }

    curl_setopt_array($ch, $options);

    $start = microtime(true);
    curl_exec($ch);
    $elapsedMs = (int) round((microtime(true) - $start) * 1000);
    $curlError = curl_errno($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError !== 0) {
        return [
            'status_code' => null,
            'response_time_ms' => null,
            'is_available' => 0,
            'curl_error' => $curlError,
            'probe_method' => $method,
        ];
    }

    $normalizedStatus = $statusCode > 0 ? $statusCode : null;

    return [
        'status_code' => $normalizedStatus,
        'response_time_ms' => $elapsedMs,
        'is_available' => isHttpStatusAvailable($normalizedStatus) ? 1 : 0,
        'curl_error' => 0,
        'probe_method' => $method,
    ];
}

function probeHttpEndpoint(string $url): array
{
    $settings = httpProbeSettings();
    $headResult = executeHttpProbe($url, $settings, 'HEAD');

    if (!shouldRetryHttpProbe($headResult, $settings)) {
        return $headResult;
    }

    $getResult = executeHttpProbe($url, $settings, 'GET');
    if ((int) ($getResult['is_available'] ?? 0) === 1) {
        $getResult['recovered_via_get'] = true;
        return $getResult;
    }

    if (($getResult['status_code'] ?? null) !== null || ($getResult['response_time_ms'] ?? null) !== null) {
        return $getResult;
    }

    return $headResult;
}

function evaluateSiteAvailability(array $endpointResults, string $mode): array
{
    if ($endpointResults === []) {
        return [
            'is_available' => 0,
            'status_code' => null,
            'response_time_ms' => null,
            'failed_endpoints' => [],
        ];
    }

    $failedEndpoints = [];
    $availableCount = 0;

    foreach ($endpointResults as $item) {
        if ((int) ($item['is_available'] ?? 0) === 1) {
            $availableCount++;
            continue;
        }

        $failedEndpoints[] = [
            'url' => (string) ($item['url'] ?? ''),
            'status_code' => $item['status_code'] ?? null,
            'response_time_ms' => $item['response_time_ms'] ?? null,
        ];
    }

    $total = count($endpointResults);
    $primary = $endpointResults[0];
    $isAvailable = match ($mode) {
        'any' => $availableCount > 0,
        'majority' => $availableCount > (int) floor($total / 2),
        'all' => $failedEndpoints === [],
        default => (int) ($primary['is_available'] ?? 0) === 1,
    };

    $statusCode = null;
    $responseSamples = [];

    if ($isAvailable) {
        foreach ($endpointResults as $item) {
            if ((int) ($item['is_available'] ?? 0) !== 1) {
                continue;
            }
            if ($statusCode === null && ($item['status_code'] ?? null) !== null) {
                $statusCode = (int) $item['status_code'];
            }
            if (($item['response_time_ms'] ?? null) !== null) {
                $responseSamples[] = (int) $item['response_time_ms'];
            }
        }
    } else {
        foreach ($endpointResults as $item) {
            if ((int) ($item['is_available'] ?? 0) === 1) {
                continue;
            }
            if ($statusCode === null && ($item['status_code'] ?? null) !== null) {
                $statusCode = (int) $item['status_code'];
            }
            if (($item['response_time_ms'] ?? null) !== null) {
                $responseSamples[] = (int) $item['response_time_ms'];
            }
            if ($mode === 'primary') {
                break;
            }
        }
    }

    $avgResponse = $responseSamples === []
        ? null
        : (int) round(array_sum($responseSamples) / count($responseSamples));

    if ($mode === 'primary') {
        $primaryUrl = (string) ($primary['url'] ?? '');
        $failedEndpoints = array_values(array_filter(
            $failedEndpoints,
            static fn(array $item): bool => ($item['url'] ?? '') === $primaryUrl
        ));
        if (!$isAvailable && $failedEndpoints === [] && (int) ($primary['is_available'] ?? 0) === 0) {
            $failedEndpoints[] = [
                'url' => $primaryUrl,
                'status_code' => $primary['status_code'] ?? null,
                'response_time_ms' => $primary['response_time_ms'] ?? null,
            ];
        }
    }

    return [
        'is_available' => $isAvailable ? 1 : 0,
        'status_code' => $statusCode,
        'response_time_ms' => $avgResponse,
        'failed_endpoints' => $failedEndpoints,
    ];
}
