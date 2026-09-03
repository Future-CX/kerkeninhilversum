<?php

declare(strict_types=1);

const CAMPAIGN_ID = 'zomerfeest-herinnering-2026-09-05';
const EMAIL_SUBJECT = 'Dankjewel voor je aanmelding - tot zaterdag!';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dit script kan alleen via de commandoregel worden uitgevoerd.');
}

$options = getopt('', ['send', 'test:', 'limit:', 'resend', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$send = isset($options['send']);
$resend = isset($options['resend']);
$testAddress = isset($options['test']) ? trim((string) $options['test']) : null;
$limit = parseLimit($options['limit'] ?? null);

if ($send && $testAddress !== null) {
    fail('Gebruik --send en --test niet tegelijk.');
}

if ($resend && !$send) {
    fail('--resend kan alleen samen met --send worden gebruikt.');
}

if ($testAddress !== null && !filter_var($testAddress, FILTER_VALIDATE_EMAIL)) {
    fail('Het testadres is geen geldig e-mailadres.');
}

$projectRoot = dirname(__DIR__);
$configPath = $projectRoot . '/api/config.php';
$templatePath = $projectRoot . '/emails/zomerfeest-aangemeld.html';

if (!is_file($configPath)) {
    fail('Databaseconfiguratie ontbreekt: api/config.php');
}

if (!is_file($templatePath)) {
    fail('E-mailsjabloon ontbreekt: emails/zomerfeest-aangemeld.html');
}

$config = require $configPath;
if (!is_array($config)) {
    fail('api/config.php moet een configuratie-array teruggeven.');
}

$htmlBody = file_get_contents($templatePath);
if ($htmlBody === false || trim($htmlBody) === '') {
    fail('Het e-mailsjabloon kon niet worden gelezen of is leeg.');
}

if ($testAddress !== null) {
    $mailConfig = readMailConfig($config);
    echo 'Testmail versturen naar ' . maskEmail($testAddress) . "...\n";

    try {
        $accepted = sendEmail($testAddress, $htmlBody, $mailConfig);
    } catch (Throwable $exception) {
        fail('Testmail mislukt: ' . $exception->getMessage());
    }

    if (!$accepted) {
        fail('De mailserver heeft de testmail niet geaccepteerd.');
    }

    echo "Testmail geaccepteerd door de mailserver. Controleer ook de inbox en spammap.\n";
    exit(0);
}

$pdo = connectDatabase($config);
$deliveryTableExists = deliveryTableExists($pdo);
$recipients = readRecipients($pdo, $deliveryTableExists && !$resend, $limit);
$recipientCount = count($recipients);

if (!$send) {
    echo "DRY-RUN: er wordt niets verstuurd.\n";
    echo sprintf(
        "%d geldig(e), uniek(e) en nog niet verzonden adres(sen) gevonden.\n",
        $recipientCount
    );
    echo "Gebruik eerst --test=jij@example.nl en daarna --send.\n";
    exit(0);
}

if ($recipientCount === 0) {
    echo "Geen adressen om te mailen.\n";
    exit(0);
}

$mailConfig = readMailConfig($config);
ensureDeliveryTable($pdo);

echo sprintf(
    "%d mail(s) versturen%s...\n",
    $recipientCount,
    $resend ? ' (opnieuw verzenden ingeschakeld)' : ''
);

$sent = 0;
$failed = 0;

foreach ($recipients as $index => $email) {
    $number = $index + 1;
    echo sprintf('[%d/%d] %s: ', $number, $recipientCount, maskEmail($email));

    try {
        $accepted = sendEmail($email, $htmlBody, $mailConfig);
    } catch (Throwable $exception) {
        $accepted = false;
        error_log('Zomerfeestmail mislukt voor ' . maskEmail($email) . ': ' . $exception->getMessage());
    }

    if ($accepted) {
        recordDelivery($pdo, $email);
        $sent++;
        echo "geaccepteerd\n";
    } else {
        $failed++;
        echo "mislukt\n";
    }

    if ($number < $recipientCount && $mailConfig['delay_ms'] > 0) {
        usleep($mailConfig['delay_ms'] * 1000);
    }
}

echo sprintf("Klaar: %d geaccepteerd, %d mislukt.\n", $sent, $failed);
echo "Let op: 'geaccepteerd' betekent dat de lokale mailserver het bericht heeft aangenomen; controleer eventuele bounces apart.\n";
exit($failed === 0 ? 0 : 1);

function showHelp(): void
{
    echo <<<TEXT
Zomerfeestmail versturen naar deelnemers uit zomerfeest_signups_2026.

Gebruik:
  php scripts/send-zomerfeest-email.php
      Alleen tellen; er wordt niets verstuurd.

  php scripts/send-zomerfeest-email.php --test=jij@example.nl
      Stuur een testmail naar één adres.

  php scripts/send-zomerfeest-email.php --send
      Stuur naar alle nog niet gemailde, geldige unieke adressen.

Opties:
  --limit=10   Verwerk maximaal 10 adressen.
  --resend     Stuur ook opnieuw naar eerder gemailde adressen (alleen met --send).
  --help       Toon deze uitleg.

TEXT;
}

function parseLimit(mixed $value): ?int
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }

    $limit = filter_var($value, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1) {
        fail('--limit moet een positief geheel getal zijn.');
    }

    return $limit;
}

function connectDatabase(array $config): PDO
{
    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            fail("Database-instelling '{$key}' ontbreekt in api/config.php.");
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset'] ?? 'utf8mb4'
    );

    try {
        return new PDO($dsn, $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $exception) {
        fail('Databaseverbinding mislukt: ' . $exception->getMessage());
    }
}

function deliveryTableExists(PDO $pdo): bool
{
    $statement = $pdo->query("SHOW TABLES LIKE 'zomerfeest_email_deliveries'");
    return $statement->fetchColumn() !== false;
}

function readRecipients(PDO $pdo, bool $excludeDelivered, ?int $limit): array
{
    $sql = $excludeDelivered
        ? 'SELECT s.email
           FROM zomerfeest_signups_2026 s
           LEFT JOIN zomerfeest_email_deliveries d
             ON d.email = LOWER(TRIM(s.email)) AND d.campaign = :campaign
           WHERE d.email IS NULL
           ORDER BY s.id ASC'
        : 'SELECT email FROM zomerfeest_signups_2026 ORDER BY id ASC';

    $statement = $pdo->prepare($sql);
    $statement->execute($excludeDelivered ? [':campaign' => CAMPAIGN_ID] : []);

    $recipients = [];
    while ($row = $statement->fetch()) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $recipients[$email] = $email;
        if ($limit !== null && count($recipients) >= $limit) {
            break;
        }
    }

    return array_values($recipients);
}

function ensureDeliveryTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS zomerfeest_email_deliveries (
            campaign VARCHAR(100) NOT NULL,
            email VARCHAR(180) NOT NULL,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY (campaign, email),
            INDEX idx_zomerfeest_email_deliveries_sent_at (sent_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
}

function recordDelivery(PDO $pdo, string $email): void
{
    $statement = $pdo->prepare(
        'INSERT INTO zomerfeest_email_deliveries (campaign, email, sent_at)
         VALUES (:campaign, :email, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at)'
    );
    $statement->execute([
        ':campaign' => CAMPAIGN_ID,
        ':email' => $email,
    ]);
}

function readMailConfig(array $config): array
{
    $transport = strtolower(trim((string) ($config['mail_transport'] ?? 'mail')));
    $from = trim((string) ($config['mail_from'] ?? ''));
    $fromName = trim((string) ($config['mail_from_name'] ?? 'Zomerfeest'));
    $replyTo = trim((string) ($config['mail_reply_to'] ?? $from));
    $delay = filter_var($config['mail_delay_ms'] ?? 250, FILTER_VALIDATE_INT);

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        fail("Voeg een geldig 'mail_from'-adres toe aan api/config.php.");
    }
    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        fail("Voeg een geldig 'mail_reply_to'-adres toe aan api/config.php.");
    }
    if (preg_match('/[\r\n]/', $fromName)) {
        fail("'mail_from_name' bevat ongeldige tekens.");
    }
    if ($delay === false || $delay < 0 || $delay > 10000) {
        fail("'mail_delay_ms' moet tussen 0 en 10000 liggen.");
    }
    if (!in_array($transport, ['mail', 'smtp'], true)) {
        fail("'mail_transport' moet 'mail' of 'smtp' zijn.");
    }

    $mailConfig = [
        'transport' => $transport,
        'from' => $from,
        'from_name' => $fromName,
        'reply_to' => $replyTo,
        'delay_ms' => $delay,
    ];

    if ($transport === 'smtp') {
        $smtpPort = filter_var($config['smtp_port'] ?? 587, FILTER_VALIDATE_INT);
        $smtpEncryption = strtolower(trim((string) ($config['smtp_encryption'] ?? 'tls')));

        if (trim((string) ($config['smtp_host'] ?? '')) === '') {
            fail("'smtp_host' ontbreekt in api/config.php.");
        }
        if ($smtpPort === false || $smtpPort < 1 || $smtpPort > 65535) {
            fail("'smtp_port' moet een geldige poort zijn.");
        }
        if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            fail("'smtp_encryption' moet 'tls', 'ssl' of 'none' zijn.");
        }
        if (trim((string) ($config['smtp_user'] ?? '')) === '') {
            fail("'smtp_user' ontbreekt in api/config.php.");
        }
        if (!isset($config['smtp_password']) || !is_string($config['smtp_password']) || $config['smtp_password'] === '') {
            fail("'smtp_password' ontbreekt in api/config.php.");
        }

        $mailConfig += [
            'smtp_host' => trim((string) $config['smtp_host']),
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
            'smtp_user' => trim((string) $config['smtp_user']),
            'smtp_password' => $config['smtp_password'],
        ];
    }

    return $mailConfig;
}

function sendEmail(string $recipient, string $htmlBody, array $mailConfig): bool
{
    $boundary = 'zomerfeest_' . bin2hex(random_bytes(12));
    $encodedSubject = mb_encode_mimeheader(EMAIL_SUBJECT, 'UTF-8', 'B', "\r\n");
    $encodedFromName = mb_encode_mimeheader(
        $mailConfig['from_name'],
        'UTF-8',
        'B',
        "\r\n"
    );

    $headers = [
        'MIME-Version: 1.0',
        sprintf('From: %s <%s>', $encodedFromName, $mailConfig['from']),
        sprintf('Reply-To: %s', $mailConfig['reply_to']),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    $plainBody = <<<TEXT
Dankjewel voor je aanmelding!

Leuk dat je erbij bent. Tot zaterdag!

Zaterdag 5 september 2026
Welkom om 16:00 uur
Oud-Loosdrechtsedijk 19, Loosdrecht

Goed om te weten:
- Kom op de fiets als dat voor jou mogelijk is.
- Wil je zwemmen? Neem dan je zwemspullen mee of trek ze thuis alvast aan.
- Heb je vrienden die ook mee willen? Ze zijn van harte welkom!
- Heb je een glutenallergie? Laat het ons dan uiterlijk vrijdag weten door deze mail te beantwoorden.

Programma en plattegrond:
https://www.kerkeninhilversum.nl/zomerfeest-programma.html

Zomerfeest - Kerken in Hilversum
TEXT;

    $body = implode("\r\n", [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        normalizeLineEndings($plainBody),
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        normalizeLineEndings($htmlBody),
        '--' . $boundary . '--',
        '',
    ]);

    if ($mailConfig['transport'] === 'smtp') {
        return sendWithSmtp(
            $recipient,
            $encodedSubject,
            $headers,
            $body,
            $mailConfig
        );
    }

    return mail(
        $recipient,
        $encodedSubject,
        $body,
        implode("\r\n", $headers)
    );
}

function sendWithSmtp(
    string $recipient,
    string $encodedSubject,
    array $headers,
    string $body,
    array $mailConfig
): bool {
    $scheme = $mailConfig['smtp_encryption'] === 'ssl' ? 'ssl' : 'tcp';
    $remote = sprintf(
        '%s://%s:%d',
        $scheme,
        $mailConfig['smtp_host'],
        $mailConfig['smtp_port']
    );
    $errorNumber = 0;
    $errorMessage = '';
    $socket = @stream_socket_client(
        $remote,
        $errorNumber,
        $errorMessage,
        20,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            sprintf('SMTP-verbinding mislukt (%d): %s', $errorNumber, $errorMessage)
        );
    }

    stream_set_timeout($socket, 20);

    try {
        expectSmtpResponse($socket, [220], 'verbinden');
        sendSmtpCommand($socket, 'EHLO localhost', [250], 'EHLO');

        if ($mailConfig['smtp_encryption'] === 'tls') {
            sendSmtpCommand($socket, 'STARTTLS', [220], 'STARTTLS');
            $cryptoEnabled = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('SMTP TLS-beveiliging kon niet worden gestart.');
            }
            sendSmtpCommand($socket, 'EHLO localhost', [250], 'EHLO na STARTTLS');
        }

        sendSmtpCommand($socket, 'AUTH LOGIN', [334], 'SMTP-authenticatie');
        sendSmtpCommand(
            $socket,
            base64_encode($mailConfig['smtp_user']),
            [334],
            'SMTP-gebruikersnaam'
        );
        sendSmtpCommand(
            $socket,
            base64_encode($mailConfig['smtp_password']),
            [235],
            'SMTP-wachtwoord'
        );
        sendSmtpCommand(
            $socket,
            'MAIL FROM:<' . $mailConfig['from'] . '>',
            [250],
            'afzender'
        );
        sendSmtpCommand(
            $socket,
            'RCPT TO:<' . $recipient . '>',
            [250, 251],
            'ontvanger'
        );
        sendSmtpCommand($socket, 'DATA', [354], 'berichtinhoud');

        $messageHeaders = array_merge([
            'Date: ' . date(DATE_RFC2822),
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
        ], $headers);
        $message = implode("\r\n", $messageHeaders)
            . "\r\n\r\n"
            . $body;
        $message = preg_replace('/^\./m', '..', $message) ?? $message;

        if (fwrite($socket, $message . "\r\n.\r\n") === false) {
            throw new RuntimeException('SMTP-bericht kon niet worden verzonden.');
        }
        expectSmtpResponse($socket, [250], 'bericht accepteren');

        @fwrite($socket, "QUIT\r\n");
        return true;
    } finally {
        fclose($socket);
    }
}

function sendSmtpCommand(
    mixed $socket,
    string $command,
    array $expectedCodes,
    string $step
): void {
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException("SMTP-opdracht mislukt bij {$step}.");
    }
    expectSmtpResponse($socket, $expectedCodes, $step);
}

function expectSmtpResponse(mixed $socket, array $expectedCodes, string $step): void
{
    $response = '';
    $code = 0;

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $matches)) {
            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }
    }

    if (!in_array($code, $expectedCodes, true)) {
        $safeResponse = trim(preg_replace('/\s+/', ' ', $response) ?? '');
        throw new RuntimeException(
            sprintf('SMTP-fout bij %s: %d %s', $step, $code, $safeResponse)
        );
    }
}

function normalizeLineEndings(string $value): string
{
    return preg_replace("/\r\n|\r|\n/", "\r\n", $value) ?? $value;
}

function maskEmail(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $visible = mb_substr($local, 0, min(2, mb_strlen($local)), 'UTF-8');
    return $visible . str_repeat('*', max(1, mb_strlen($local) - 2)) . '@' . $domain;
}

function fail(string $message): never
{
    fwrite(STDERR, "Fout: {$message}\n");
    exit(1);
}
