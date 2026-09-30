<?php
/**
 * EER contact form handler (Bluehost / PHP).
 *
 * 1. Rejects bots (honeypot, too-fast submit, per-IP rate limit).
 * 2. Appends every real lead to a JSONL log outside all web roots (~/eer-leads/leads.jsonl)
 *    so no lead is lost even if mail delivery fails.
 * 3. Emails the lead to LEAD_TO with a fixed, machine-readable body for AI inbox agents.
 *
 * Returns JSON when called with Accept: application/json, otherwise redirects to /thank-you.html.
 */

const LEAD_TO     = 'mike@energyefficientroofer.com';
const MAIL_FROM   = 'website@energyefficientroofer.com';
const MIN_SECONDS = 3;      // faster than this = bot
const RATE_LIMIT  = 5;      // max submits per IP per hour

$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function respond($ok, $error = '', $code = 200) {
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($ok ? 200 : $code);
        header('Content-Type: application/json');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    } elseif ($ok) {
        header('Location: /thank-you.html', true, 303);
    } else {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $error . " Please call or text (203) 340-0036.";
    }
    exit;
}

function clean($key, $max) {
    $v = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    return mb_substr(strip_tags($v), 0, $max);
}

function one_line($v) {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v)); // blocks header injection
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /contact.html', true, 303);
    exit;
}

// Storage outside every web root. The site lives in ~/public_html/energyefficientroofer and
// ~/public_html is itself a web root for other domains, so go two levels up to the home dir.
$dataDir = dirname(__DIR__, 2) . '/eer-leads';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0700, true);
}
if (!is_file($dataDir . '/.htaccess')) {
    @file_put_contents($dataDir . '/.htaccess', "Require all denied\n");
}

// --- Bot checks: silently "succeed" so bots don't retry ---
if (clean('website', 200) !== '') {
    respond(true);
}
$ts = (int) clean('ts', 20);
if ($ts > 0 && (time() - $ts) < MIN_SECONDS) {
    respond(true);
}

$ip = one_line($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = $dataDir . '/rate-' . md5($ip) . '.txt';
$recent = array_filter(
    is_file($rateFile) ? array_map('intval', file($rateFile, FILE_IGNORE_NEW_LINES)) : [],
    function ($t) { return $t > time() - 3600; }
);
if (count($recent) >= RATE_LIMIT) {
    respond(false, 'Too many requests from this connection.', 429);
}
$recent[] = time();
@file_put_contents($rateFile, implode("\n", $recent));

// --- Validate ---
$name    = one_line(clean('name', 120));
$phone   = one_line(clean('phone', 40));
$email   = one_line(clean('email', 160));
$service = one_line(clean('service', 40));
$message = clean('message', 4000);

$services = ['roofing', 'siding', 'gutters', 'solar', 'commercial', 'insurance-claim'];
if ($name === '' || $phone === '') {
    respond(false, 'Name and phone are required.', 422);
}
if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
    respond(false, 'Please enter a 10-digit phone number.', 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'That email address does not look right.', 422);
}
if (!in_array($service, $services, true)) {
    $service = 'other';
}

$lead = [
    'lead_id'     => 'EER-' . date('Ymd-His') . '-' . substr(md5($ip . microtime()), 0, 4),
    'received_at' => date('c'),
    'source'      => 'energyefficientroofer.com/contact',
    'name'        => $name,
    'phone'       => $phone,
    'email'       => $email,
    'service'     => $service,
    'message'     => $message,
    'ip'          => $ip,
    'user_agent'  => one_line(mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)),
];

$logged = @file_put_contents($dataDir . '/leads.jsonl', json_encode($lead) . "\n", FILE_APPEND | LOCK_EX) !== false;

// --- Email: fixed labels so AI agents can parse it ---
$subject = sprintf('[EER Lead] %s - %s - %s', $service, $name, $phone);
$body  = "New website lead for Energy Efficient Roofer\n";
$body .= "==========================================\n";
$body .= "Lead ID: {$lead['lead_id']}\n";
$body .= "Received: {$lead['received_at']}\n";
$body .= "Source: {$lead['source']}\n";
$body .= "Name: {$name}\n";
$body .= "Phone: {$phone}\n";
$body .= "Email: " . ($email !== '' ? $email : '(none given)') . "\n";
$body .= "Service: {$service}\n";
$body .= "Message:\n" . ($message !== '' ? $message : '(none)') . "\n";
$body .= "==========================================\n";
$body .= "Reply to this email to answer the homeowner directly" . ($email !== '' ? '.' : ' (no email given - call or text).') . "\n";

$headers  = "From: EER Website <" . MAIL_FROM . ">\r\n";
if ($email !== '') {
    $headers .= "Reply-To: " . $name . " <" . $email . ">\r\n";
}
$headers .= "X-EER-Lead-ID: {$lead['lead_id']}\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail(LEAD_TO, $subject, $body, $headers, '-f' . MAIL_FROM);

if (!$sent && !$logged) {
    respond(false, 'Our form hit an error.', 500);
}
respond(true);
