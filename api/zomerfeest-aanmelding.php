<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, ['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Methode niet toegestaan.']);
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    respond(500, ['error' => 'Databaseconfiguratie ontbreekt.']);
}

$config = require $configPath;
$payload = readPayload();
[$signup, $errors] = validateSignup($payload);

if ($errors !== []) {
    respond(400, ['error' => 'Controleer de velden.', 'fields' => $errors]);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $statement = $pdo->prepare(
        'INSERT INTO zomerfeest_signups_2026
            (created_at, name, email, age, church_or_city, brings_friend, friend_name)
         VALUES
            (UTC_TIMESTAMP(), :name, :email, :age, :church_or_city, :brings_friend, :friend_name)
         ON DUPLICATE KEY UPDATE
            created_at = UTC_TIMESTAMP(),
            name = VALUES(name),
            age = VALUES(age),
            church_or_city = VALUES(church_or_city),
            brings_friend = VALUES(brings_friend),
            friend_name = VALUES(friend_name)'
    );
    $statement->execute([
        ':name' => $signup['name'],
        ':email' => $signup['email'],
        ':age' => $signup['age'],
        ':church_or_city' => $signup['church_or_city'],
        ':brings_friend' => $signup['brings_friend'],
        ':friend_name' => $signup['friend_name'],
    ]);

    respond(201, ['ok' => true]);
} catch (Throwable $exception) {
    error_log('Zomerfeest signup failed: ' . $exception->getMessage());
    respond(500, ['error' => 'Aanmelden lukt nu niet.']);
}

function readPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody ?: '', true);
        return is_array($payload) ? $payload : [];
    }

    return $_POST;
}

function validateSignup(array $payload): array
{
    $name = cleanText($payload['name'] ?? '', 120);
    $email = strtolower(cleanText($payload['email'] ?? '', 180));
    $churchOrCity = cleanText($payload['churchOrCity'] ?? '', 180);
    $friendName = cleanText($payload['friendName'] ?? '', 120);
    $bringsFriend = filter_var($payload['bringsFriend'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $age = filter_var($payload['age'] ?? null, FILTER_VALIDATE_INT);
    $errors = [];

    if ($name === '') {
        $errors['name'] = 'Vul je naam in.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Vul een geldig e-mailadres in.';
    }
    if ($age === false || $age < 1 || $age > 100) {
        $errors['age'] = 'Vul een leeftijd tussen 1 en 100 in.';
    }
    if ($bringsFriend && $friendName === '') {
        $errors['friendName'] = 'Vul de naam van je vriend of vriendin in.';
    }

    if ($errors !== []) {
        return [null, $errors];
    }

    return [[
        'name' => $name,
        'email' => $email,
        'age' => $age,
        'church_or_city' => $churchOrCity,
        'brings_friend' => $bringsFriend ? 1 : 0,
        'friend_name' => $friendName,
    ], []];
}

function cleanText(mixed $value, int $limit): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return mb_substr($value, 0, $limit, 'UTF-8');
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
