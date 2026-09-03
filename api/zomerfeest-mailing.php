<?php

declare(strict_types=1);

const MAILING_CAMPAIGN = 'zomerfeest-herinnering-2026-09-05';
const MAILING_SUBJECT = 'Dankjewel voor je aanmelding - tot zaterdag!';
const MAILING_BATCH_SIZE = 10;

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

if (!isHttpsRequest()) {
    http_response_code(400);
    exit('Open deze beheerpagina via HTTPS.');
}

session_name('zomerfeest_mailing_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    renderError('De serverconfiguratie ontbreekt.');
}

$config = require $configPath;
if (!is_array($config)) {
    renderError('De serverconfiguratie is ongeldig.');
}

$adminPassword = (string) (
    getenv('ZOMERFEEST_MAIL_ADMIN_PASSWORD')
    ?: ($config['mail_admin_password'] ?? '')
);
if (strlen($adminPassword) < 16) {
    renderError(
        "Stel eerst een sterk 'mail_admin_password' van minimaal 16 tekens in."
    );
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $lastAttempt = (int) ($_SESSION['last_login_attempt'] ?? 0);
    if (time() - $lastAttempt < 2) {
        sleep(2);
    }
    $_SESSION['last_login_attempt'] = time();

    $submittedPassword = is_string($_POST['password'] ?? null)
        ? $_POST['password']
        : '';

    if (hash_equals($adminPassword, $submittedPassword)) {
        session_regenerate_id(true);
        $_SESSION['mailing_authenticated'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset($_SESSION['last_login_attempt']);
        redirectToSelf();
    }

    renderLogin('Het wachtwoord is niet correct.');
}

if (empty($_SESSION['mailing_authenticated'])) {
    renderLogin();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirectToSelf();
    }

    try {
        if ($action === 'test') {
            sendTestMail($config);
        } elseif ($action === 'send_batch') {
            sendBatch($config);
        }
    } catch (Throwable $exception) {
        error_log('Zomerfeest mailing beheer: ' . $exception->getMessage());
        setFlash('De actie is mislukt. Controleer de serverlog of configuratie.', 'error');
    }

    redirectToSelf();
}

try {
    $pdo = connectMailingDatabase($config);
    $counts = readMailingCounts($pdo);
} catch (Throwable $exception) {
    error_log('Zomerfeest mailing dashboard: ' . $exception->getMessage());
    renderError('De database kon niet worden gelezen.');
}

$flash = $_SESSION['mailing_flash'] ?? null;
unset($_SESSION['mailing_flash']);
renderDashboard($counts, is_array($flash) ? $flash : null);

function sendTestMail(array $config): void
{
    $email = strtolower(trim((string) ($_POST['test_email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('Vul een geldig testadres in.', 'error');
        return;
    }

    $html = readMailingTemplate();
    $mailConfig = readOnlineMailConfig($config);

    if (!sendOnlineMail($email, $html, $mailConfig)) {
        throw new RuntimeException('PHP mail() accepteerde de testmail niet.');
    }

    setFlash('De testmail is door de mailserver geaccepteerd.', 'success');
}

function sendBatch(array $config): void
{
    if (($_POST['confirm'] ?? '') !== 'yes') {
        setFlash('Bevestig eerst dat je de volgende batch wilt versturen.', 'error');
        return;
    }

    $pdo = connectMailingDatabase($config);
    $lockStatement = $pdo->query("SELECT GET_LOCK('zomerfeest_mailing_2026', 0)");
    if ((int) $lockStatement->fetchColumn() !== 1) {
        setFlash('Er wordt al een batch verwerkt. Probeer het zo opnieuw.', 'error');
        return;
    }

    try {
        ensureMailingDeliveryTable($pdo);
        $recipients = readPendingRecipients($pdo, MAILING_BATCH_SIZE);
        if ($recipients === []) {
            setFlash('Er zijn geen nieuwe adressen meer om te mailen.', 'success');
            return;
        }

        $html = readMailingTemplate();
        $mailConfig = readOnlineMailConfig($config);
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $index => $email) {
            if (sendOnlineMail($email, $html, $mailConfig)) {
                recordMailingDelivery($pdo, $email);
                $sent++;
            } else {
                $failed++;
                error_log('Zomerfeestmail niet geaccepteerd voor ' . maskMailingEmail($email));
            }

            if ($index < count($recipients) - 1 && $mailConfig['delay_ms'] > 0) {
                usleep($mailConfig['delay_ms'] * 1000);
            }
        }

        $message = sprintf(
            'Batch afgerond: %d geaccepteerd, %d mislukt.',
            $sent,
            $failed
        );
        setFlash($message, $failed === 0 ? 'success' : 'error');
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('zomerfeest_mailing_2026')");
    }
}

function connectMailingDatabase(array $config): PDO
{
    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException("Database-instelling {$key} ontbreekt.");
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function readMailingCounts(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT DISTINCT LOWER(TRIM(email)) AS email
         FROM zomerfeest_signups_2026
         WHERE email IS NOT NULL AND TRIM(email) <> ''"
    );
    $validEmails = [];
    while ($row = $statement->fetch()) {
        $email = (string) ($row['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validEmails[$email] = true;
        }
    }
    $total = count($validEmails);

    if (!mailingDeliveryTableExists($pdo)) {
        return ['total' => $total, 'sent' => 0, 'pending' => $total];
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM zomerfeest_email_deliveries WHERE campaign = :campaign'
    );
    $statement->execute([':campaign' => MAILING_CAMPAIGN]);
    $sent = (int) $statement->fetchColumn();

    return [
        'total' => $total,
        'sent' => $sent,
        'pending' => max(0, $total - $sent),
    ];
}

function mailingDeliveryTableExists(PDO $pdo): bool
{
    $statement = $pdo->query("SHOW TABLES LIKE 'zomerfeest_email_deliveries'");
    return $statement->fetchColumn() !== false;
}

function ensureMailingDeliveryTable(PDO $pdo): void
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

function readPendingRecipients(PDO $pdo, int $limit): array
{
    $statement = $pdo->prepare(
        'SELECT LOWER(TRIM(s.email)) AS email
         FROM zomerfeest_signups_2026 s
         LEFT JOIN zomerfeest_email_deliveries d
           ON d.email = LOWER(TRIM(s.email)) AND d.campaign = :campaign
         WHERE d.email IS NULL
         GROUP BY LOWER(TRIM(s.email))
         ORDER BY MIN(s.id) ASC'
    );
    $statement->execute([':campaign' => MAILING_CAMPAIGN]);

    $recipients = [];
    while ($row = $statement->fetch()) {
        $email = (string) ($row['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $email;
            if (count($recipients) >= $limit) {
                break;
            }
        }
    }
    return $recipients;
}

function recordMailingDelivery(PDO $pdo, string $email): void
{
    $statement = $pdo->prepare(
        'INSERT INTO zomerfeest_email_deliveries (campaign, email, sent_at)
         VALUES (:campaign, :email, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at)'
    );
    $statement->execute([
        ':campaign' => MAILING_CAMPAIGN,
        ':email' => $email,
    ]);
}

function readOnlineMailConfig(array $config): array
{
    $from = trim((string) ($config['mail_from'] ?? ''));
    $replyTo = trim((string) ($config['mail_reply_to'] ?? $from));
    $fromName = trim((string) ($config['mail_from_name'] ?? 'Zomerfeest'));
    $delay = filter_var($config['mail_delay_ms'] ?? 250, FILTER_VALIDATE_INT);

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('mail_from ontbreekt of is ongeldig.');
    }
    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('mail_reply_to ontbreekt of is ongeldig.');
    }
    if (preg_match('/[\r\n]/', $fromName)) {
        throw new RuntimeException('mail_from_name is ongeldig.');
    }
    if ($delay === false || $delay < 0 || $delay > 10000) {
        throw new RuntimeException('mail_delay_ms is ongeldig.');
    }

    return [
        'from' => $from,
        'reply_to' => $replyTo,
        'from_name' => $fromName,
        'delay_ms' => $delay,
    ];
}

function readMailingTemplate(): string
{
    $path = dirname(__DIR__) . '/emails/zomerfeest-aangemeld.html';
    $html = file_get_contents($path);
    if ($html === false || trim($html) === '') {
        throw new RuntimeException('Het HTML-e-mailsjabloon ontbreekt of is leeg.');
    }
    return $html;
}

function sendOnlineMail(string $recipient, string $html, array $mailConfig): bool
{
    $boundary = 'zomerfeest_' . bin2hex(random_bytes(12));
    $subject = mb_encode_mimeheader(MAILING_SUBJECT, 'UTF-8', 'B', "\r\n");
    $fromName = mb_encode_mimeheader(
        $mailConfig['from_name'],
        'UTF-8',
        'B',
        "\r\n"
    );
    $headers = [
        'MIME-Version: 1.0',
        sprintf('From: %s <%s>', $fromName, $mailConfig['from']),
        sprintf('Reply-To: %s', $mailConfig['reply_to']),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $plain = "Dankjewel voor je aanmelding!\n\n"
        . "Tot zaterdag. Je bent welkom om 16:00 uur aan de Oud-Loosdrechtsedijk 19 in Loosdrecht.\n\n"
        . "Kom indien mogelijk op de fiets. Wil je zwemmen? Neem je zwemspullen mee of trek ze alvast aan. Vrienden zijn van harte welkom. Heb je een glutenallergie? Laat het ons uiterlijk vrijdag weten door deze mail te beantwoorden.\n\n"
        . "Programma en plattegrond: https://www.kerkeninhilversum.nl/zomerfeest-programma.html";
    $body = implode("\r\n", [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        normalizeMailingLines($plain),
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        normalizeMailingLines($html),
        '--' . $boundary . '--',
        '',
    ]);

    return mail($recipient, $subject, $body, implode("\r\n", $headers));
}

function normalizeMailingLines(string $value): string
{
    return preg_replace("/\r\n|\r|\n/", "\r\n", $value) ?? $value;
}

function maskMailingEmail(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 2) . '***@' . $domain;
}

function verifyCsrfToken(): void
{
    $known = (string) ($_SESSION['csrf_token'] ?? '');
    $submitted = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token']
        : '';
    if ($known === '' || !hash_equals($known, $submitted)) {
        http_response_code(403);
        exit('Ongeldige beveiligingscontrole. Vernieuw de pagina.');
    }
}

function setFlash(string $message, string $type): void
{
    $_SESSION['mailing_flash'] = ['message' => $message, 'type' => $type];
}

function csrfField(): string
{
    $token = htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function redirectToSelf(): never
{
    $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/zomerfeest-mailing.php');
    header('Location: ' . $path, true, 303);
    exit;
}

function isHttpsRequest(): bool
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $isLocal = in_array(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ['127.0.0.1', '::1'],
        true
    );
    return $isHttps || $isLocal;
}

function renderLogin(?string $error = null): never
{
    $message = $error === null
        ? ''
        : '<p class="message error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    echo pageStart('Inloggen') . <<<HTML
      <main class="panel narrow">
        <p class="kicker">Zomerfeest</p>
        <h1>Mailingbeheer</h1>
        <p>Log in om een testmail of deelnemersmailing te versturen.</p>
        {$message}
        <form method="post">
          <input type="hidden" name="action" value="login">
          <label for="password">Beheerwachtwoord</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
          <button type="submit">Inloggen</button>
        </form>
      </main>
    HTML . pageEnd();
    exit;
}

function renderDashboard(array $counts, ?array $flash): never
{
    $csrf = csrfField();
    $message = '';
    if ($flash !== null) {
        $type = $flash['type'] === 'success' ? 'success' : 'error';
        $text = htmlspecialchars((string) $flash['message'], ENT_QUOTES, 'UTF-8');
        $message = "<p class=\"message {$type}\">{$text}</p>";
    }
    $disabled = $counts['pending'] === 0 ? ' disabled' : '';
    echo pageStart('Mailingbeheer') . <<<HTML
      <main class="panel">
        <div class="topline">
          <div>
            <p class="kicker">Zomerfeest</p>
            <h1>Mailingbeheer</h1>
          </div>
          <form method="post">
            {$csrf}
            <input type="hidden" name="action" value="logout">
            <button class="quiet" type="submit">Uitloggen</button>
          </form>
        </div>
        {$message}
        <div class="counts" aria-label="Mailingstatus">
          <div><strong>{$counts['total']}</strong><span>adressen</span></div>
          <div><strong>{$counts['sent']}</strong><span>verzonden</span></div>
          <div><strong>{$counts['pending']}</strong><span>resterend</span></div>
        </div>
        <section>
          <h2>1. Stuur een test</h2>
          <p>Controleer de inhoud en bezorging voordat je deelnemers mailt.</p>
          <form method="post">
            {$csrf}
            <input type="hidden" name="action" value="test">
            <label for="test_email">Jouw e-mailadres</label>
            <input id="test_email" name="test_email" type="email" autocomplete="email" required>
            <button type="submit">Testmail versturen</button>
          </form>
        </section>
        <section>
          <h2>2. Verstuur de mailing</h2>
          <p>Per klik worden maximaal 10 nog niet gemailde adressen verwerkt.</p>
          <form method="post">
            {$csrf}
            <input type="hidden" name="action" value="send_batch">
            <label class="confirm"><input type="checkbox" name="confirm" value="yes" required> Ik heb de testmail gecontroleerd.</label>
            <button class="send" type="submit"{$disabled}>Volgende batch versturen</button>
          </form>
        </section>
      </main>
    HTML . pageEnd();
    exit;
}

function renderError(string $message): never
{
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo pageStart('Configuratiefout') . <<<HTML
      <main class="panel narrow">
        <p class="kicker">Zomerfeest</p>
        <h1>Configuratiefout</h1>
        <p class="message error">{$safeMessage}</p>
      </main>
    HTML . pageEnd();
    exit;
}

function pageStart(string $title): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>{$safeTitle} | Zomerfeest</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #f2f5f6; color: #071b2f; font-family: Inter, Arial, sans-serif; }
    .panel { width: min(680px, calc(100% - 28px)); margin: 32px auto; background: #fff; border-radius: 12px; box-shadow: 0 14px 40px rgba(7,27,47,.12); padding: clamp(22px, 6vw, 42px); }
    .narrow { max-width: 460px; }
    .topline { display: flex; align-items: start; justify-content: space-between; gap: 20px; }
    .kicker { margin: 0 0 8px; color: #ec2f8c; font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    h1 { margin: 0 0 14px; font-size: clamp(32px, 8vw, 48px); line-height: 1; }
    h2 { margin: 0 0 8px; font-size: 22px; }
    p { line-height: 1.55; color: #59636a; }
    section { border-top: 1px solid #dde3e5; margin-top: 28px; padding-top: 26px; }
    form { display: grid; gap: 10px; }
    label { font-size: 14px; font-weight: 750; }
    input[type=email], input[type=password] { width: 100%; min-height: 46px; border: 1px solid #cbd4d8; border-radius: 7px; padding: 10px 12px; font: inherit; }
    input:focus { outline: 3px solid rgba(255,192,0,.35); border-color: #071b2f; }
    button { min-height: 46px; border: 0; border-radius: 7px; background: #071b2f; color: #fff; padding: 11px 16px; font: inherit; font-weight: 800; cursor: pointer; }
    button.send { background: #ffc000; color: #071b2f; }
    button.quiet { background: #f2f5f6; color: #071b2f; min-height: 40px; }
    button:disabled { opacity: .45; cursor: not-allowed; }
    .message { border-radius: 7px; padding: 12px 14px; font-weight: 700; }
    .message.success { background: #e5f6ef; color: #0d6547; }
    .message.error { background: #fdeaea; color: #8d2020; }
    .counts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 26px; }
    .counts div { background: #f2f5f6; border-radius: 8px; padding: 16px 10px; text-align: center; }
    .counts strong, .counts span { display: block; }
    .counts strong { font-size: 26px; }
    .counts span { color: #59636a; font-size: 12px; }
    .confirm { display: flex; align-items: flex-start; gap: 9px; font-weight: 600; margin: 8px 0; }
    .confirm input { margin-top: 3px; }
    @media (max-width: 480px) { .topline { display: grid; } .counts strong { font-size: 22px; } }
  </style>
</head>
<body>
HTML;
}

function pageEnd(): string
{
    return "</body>\n</html>";
}
