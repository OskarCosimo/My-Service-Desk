<?php
// api/v1/tickets.php
// REST API endpoint for creating tickets using an Agent API Key

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/notifications_helper.php';

// Only allow POST requests for ticket creation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method Not Allowed. Only POST is accepted.'
    ]);
    exit;
}

// 1. Extract API Key from HTTP Headers (Authorization Bearer or X-API-Key)
$apiKey = '';

// Try Authorization header first
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $apiKey = trim($matches[1]);
}

// Fallback to X-API-Key header
if (empty($apiKey)) {
    $apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
}

if (empty($apiKey)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized. API Key is missing (use Authorization: Bearer <KEY> or X-API-Key: <KEY>).'
    ]);
    exit;
}

// 2. Validate API Key against database
$stmtKey = $pdo->prepare("
    SELECT ak.*, u.id AS agent_user_id, u.username, u.email AS agent_email, u.role, u.is_banned, u.is_approved
    FROM api_keys ak
    INNER JOIN users u ON ak.user_id = u.id
    WHERE ak.api_key = ?
    LIMIT 1
");
$stmtKey->execute([$apiKey]);
$keyRecord = $stmtKey->fetch();

if (!$keyRecord || (int)$keyRecord['is_active'] !== 1) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Forbidden. The provided API Key is invalid or has been revoked.'
    ]);
    exit;
}

if (!empty($keyRecord['is_banned']) || empty($keyRecord['is_approved'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Forbidden. The associated user account is suspended or not approved.'
    ]);
    exit;
}

// 3. Parse input data (Supports JSON or form POST)
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!is_array($data)) {
    $data = $_POST;
}

$subject     = trim($data['subject'] ?? '');
$message     = trim($data['message'] ?? '');
$guestEmail  = trim($data['email'] ?? $data['guest_email'] ?? '');
$guestName   = trim($data['name'] ?? $data['guest_name'] ?? '');
$categoryId  = !empty($data['category_id']) ? (int)$data['category_id'] : null;

// Validate required fields
if (empty($subject) || empty($message)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'Validation Failed: "subject" and "message" are required.'
    ]);
    exit;
}

if (empty($guestEmail) || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'Validation Failed: A valid "email" (or "guest_email") is required.'
    ]);
    exit;
}

// Validate category exists if provided
if ($categoryId !== null) {
    $stmtCatCheck = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
    $stmtCatCheck->execute([$categoryId]);
    if (!$stmtCatCheck->fetch()) {
        $categoryId = null;
    }
}

// 4. Generate ticket tokens and insert ticket
$trackingCode = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 3) . '-' . substr(md5(uniqid((string)mt_rand(), true)), 0, 3) . '-' . substr(md5(uniqid((string)mt_rand(), true)), 0, 3));
$accessToken  = bin2hex(random_bytes(32));
$agentUserId  = (int)$keyRecord['agent_user_id'];

// Check if customer email matches an existing user
$stmtCustomerCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmtCustomerCheck->execute([$guestEmail]);
$matchedUser = $stmtCustomerCheck->fetch();
$ticketUserId = $matchedUser ? (int)$matchedUser['id'] : null;

try {
    $stmtInsert = $pdo->prepare("
        INSERT INTO tickets (tracking_code, access_token, user_id, category_id, guest_email, guest_name, subject, message, assigned_to) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // Automatically assign the ticket to the agent owner of this API Key
    $stmtInsert->execute([
        $trackingCode,
        $accessToken,
        $ticketUserId,
        $categoryId,
        $guestEmail,
        $guestName ?: 'Customer',
        $subject,
        $message,
        $agentUserId
    ]);

    $ticketId = (int)$pdo->lastInsertId();

    // 5. Send Notification and Mail
    $createdTicket = [
        'id'            => $ticketId,
        'assigned_to'   => $agentUserId,
        'tracking_code' => $trackingCode,
        'access_token'  => $accessToken
    ];

    notify_ticket_followers(
        $pdo,
        $createdTicket,
        "New API Ticket #" . $trackingCode,
        "Subject: " . $subject . " (Created by API via " . $keyRecord['username'] . ")",
        $agentUserId
    );

    $trackingUrl = "https://" . $_SERVER['HTTP_HOST'] . "/track.php?code=" . $trackingCode . "&token=" . $accessToken . "&email=" . urlencode($guestEmail);
    $emailBody  = "<h3>Ticket Submitted Successfully via API</h3>";
    $emailBody .= "<p>Your ticket reference code is: <strong>{$trackingCode}</strong></p>";
    $emailBody .= "<p>You can track the progress of your ticket using the link below:</p>";
    $emailBody .= "<p><a href='{$trackingUrl}'>{$trackingUrl}</a></p>";

    send_ticket_email($pdo, $guestEmail, $guestName ?: 'Customer', "Ticket Received: {$trackingCode}", $emailBody);

    // AI Queue support if enabled
    if (get_setting($pdo, 'ai_enabled', '0') === '1' && get_setting($pdo, 'ai_auto_respond', '0') === '1') {
        $stmtQueue = $pdo->prepare("INSERT INTO ai_queue (ticket_id) VALUES (?)");
        $stmtQueue->execute([$ticketId]);

        $workerUrl = "https://" . $_SERVER['HTTP_HOST'] . "/api/process_ai_queue.php";
        $ch = curl_init($workerUrl);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'InternalWorker/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    }

    // Category name for hooks
    $categoryName = 'General';
    if ($categoryId) {
        $stmtCat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $stmtCat->execute([$categoryId]);
        $categoryName = $stmtCat->fetchColumn() ?: 'General';
    }

    trigger_hook('on_ticket_created', [
        'ticket' => [
            'tracking_code' => $trackingCode,
            'access_token'  => $accessToken,
            'subject'       => $subject,
            'guest_name'    => $guestName
        ],
        'category_name' => $categoryName
    ]);

    http_response_code(201);
    echo json_encode([
        'success'       => true,
        'message'       => 'Ticket created successfully.',
        'ticket_id'     => $ticketId,
        'tracking_code' => $trackingCode,
        'tracking_url'  => $trackingUrl,
        'created_by'    => [
            'agent_id'   => $agentUserId,
            'agent_name' => $keyRecord['username']
        ]
    ]);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error occurred: ' . $e->getMessage()
    ]);
    exit;
}