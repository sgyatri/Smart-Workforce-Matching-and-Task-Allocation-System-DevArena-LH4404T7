<?php
// public/mark_notification_read.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// start session (if not started)
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

// Quick GET debug: visit this URL in browser while logged in as manager to confirm endpoint is reachable
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok' => true,
        'debug' => true,
        'logged_in_as_manager' => isset($_SESSION['manager_id']) ? (int)$_SESSION['manager_id'] : null,
        'msg' => 'mark_notification_read endpoint reachable (GET)'
    ]);
    exit;
}

// Only accept JSON POST for actual marking
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || !isset($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => 'Invalid request body (expected JSON {id:...})']);
    exit;
}

$id = intval($input['id']);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => 'Invalid id']);
    exit;
}

if (!isset($_SESSION['manager_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Not authenticated as manager']);
    exit;
}

$mgr = intval($_SESSION['manager_id']);

try {
    // verify ownership
    $q = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND manager_id = ?");
    $q->execute([$id, $mgr]);
    if (!$q->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'msg' => 'Notification not found or not yours']);
        exit;
    }

    $u = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $ok = $u->execute([$id]);

    if ($ok) {
        echo json_encode(['success' => true]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'msg' => 'DB update failed']);
        exit;
    }
} catch (Exception $e) {
    // log server-side, but do not expose internals to client
    error_log("mark_notification_read.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'msg' => 'Server error (check logs)']);
    exit;
}
