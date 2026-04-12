<?php

declare(strict_types=1);

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

loadEnv(__DIR__ . '/.env');

function env(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    if ($val !== false) {
        return $val;
    }
    return $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('MYSQL_HOST', 'localhost') ?? 'localhost';
    $port = env('MYSQL_PORT', '3306') ?? '3306';
    $user = env('MYSQL_USER', '') ?? '';
    $pass = env('MYSQL_PASSWORD', '') ?? '';
    $name = env('MYSQL_DATABASE', '') ?? '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function apiToken(): string
{
    return env('API_TOKEN', '') ?? '';
}

function isDebug(): bool
{
    return (env('DEBUG', '0') ?? '0') === '1';
}

function apiTokenHash(): string
{
    return env('API_TOKEN_HASH', '') ?? '';
}

function hashToken(string $token): string
{
    return hash('sha256', $token);
}

function remoteApiUrl(): string
{
    return env('API_REMOTE_URL', '') ?? '';
}

function remoteApiToken(): string
{
    return env('API_REMOTE_TOKEN', '') ?? '';
}

function requireToken(): void
{
    $token = apiToken();
    $tokenHash = apiTokenHash();
    if ($token === '') {
        if ($tokenHash === '') {
            return;
        }
    }

    $header = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $query = $_GET['token'] ?? '';
    $provided = $header !== '' ? $header : $query;
    if ($provided === '') {
        jsonResponse(['ok' => false, 'error' => 'Token requerido'], 401);
    }

    if ($token !== '') {
        if ($provided !== $token) {
            jsonResponse(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        return;
    }

    if ($tokenHash !== '') {
        if (hashToken($provided) !== $tokenHash) {
            jsonResponse(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        return;
    }
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?:  '', true);
    return is_array($data) ? $data : [];
}

function isSaturday(string $fecha): bool
{
    $date = new DateTime($fecha . ' 00:00:00');
    return (int)$date->format('w') === 6;
}

function normalizeCedula(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function isValidCedulaUy(string $value): bool
{
    $digits = normalizeCedula($value);
    if (strlen($digits) < 7 || strlen($digits) > 8) {
        return false;
    }
    $digits = str_pad($digits, 8, '0', STR_PAD_LEFT);
    $nums = array_map('intval', str_split($digits));
    $weights = [2, 9, 8, 7, 6, 3, 4];
    $sum = 0;
    for ($i = 0; $i < 7; $i++) {
        $sum += $nums[$i] * $weights[$i];
    }
    $check = (10 - ($sum % 10)) % 10;
    return $check === $nums[7];
}

function normalizeMatricula(string $value): string
{
    return strtoupper(trim($value));
}

function normalizeTelefonoUy(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '598')) {
        $digits = substr($digits, 3);
    }
    if (str_starts_with($digits, '9')) {
        $digits = '0' . $digits;
    }
    if (!str_starts_with($digits, '0')) {
        $digits = '0' . $digits;
    }
    $digits = substr($digits, 0, 9);
    if (strlen($digits) !== 9) {
        return '';
    }
    // Guardar local con prefijo 09 (9 d�gitos)
    return $digits;
}

function isValidTelefonoUy(string $value): bool
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '598')) {
        $digits = substr($digits, 3);
    }
    if (str_starts_with($digits, '9')) {
        $digits = '0' . $digits;
    }
    if (!str_starts_with($digits, '0')) {
        $digits = '0' . $digits;
    }
    return strlen($digits) === 9 && str_starts_with($digits, '0');
}
function isValidMatriculaUy(string $value): bool
{
    return (bool) preg_match('/^[A-Z]{3}\d{3,4}$/', normalizeMatricula($value));
}

function allowedOrigins(): array
{
    $raw = trim((string)(env('ALLOWED_ORIGINS', '') ?? ''));
    if ($raw === '') {
        return [];
    }
    $parts = array_map('trim', explode(',', $raw));
    return array_values(array_filter($parts, fn($v) => $v !== ''));
}

function isOriginAllowed(string $origin): bool
{
    $origin = trim($origin);
    if ($origin === '') {
        return true;
    }
    $allowed = allowedOrigins();
    if (count($allowed) === 0) {
        return true;
    }
    return in_array($origin, $allowed, true);
}

function getClientIp(): string
{
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '') {
        return $realIp;
    }
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function rateLimitCheck(string $scope, int $maxRequests, int $windowSeconds): bool
{
    $ip = getClientIp();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180);
    $key = hash('sha256', $scope . '|' . $ip . '|' . $ua);
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reservas_rate_limit';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    $now = time();
    $data = ['start' => $now, 'count' => 0];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $parsed = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($parsed) && isset($parsed['start'], $parsed['count'])) {
            $data['start'] = (int)$parsed['start'];
            $data['count'] = (int)$parsed['count'];
        }
    }

    if (($now - $data['start']) >= $windowSeconds) {
        $data = ['start' => $now, 'count' => 0];
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data));

    return $data['count'] <= $maxRequests;
}







