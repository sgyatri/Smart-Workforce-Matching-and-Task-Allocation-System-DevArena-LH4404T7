<?php
session_start();
header('Content-Type: application/json');
// include DB connection
require_once __DIR__ . '/includes/db.php'; // adjust path

// security: ensure worker is logged in
if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}

$worker_id = intval($_SESSION['worker_id']);

$input = json_decode(file_get_contents('php://input'), true);
$task_id = intval($input['task_id'] ?? 0);
$completed = intval($input['completed'] ?? 0);

if ($task_id <= 0) {
    echo json_encode(['success' => false, 'msg' => 'Invalid task']);
    exit;
}

// verify the task belongs to this worker
$stmt = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND worker_id = ?");
$stmt->bind_param("ii", $task_id, $worker_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    echo json_encode(['success' => false, 'msg' => 'Task not found or not assigned to you']);
    exit;
}
$stmt->close();

if ($completed) {
    $sql = "UPDATE tasks SET completed = 1, completed_at = NOW() WHERE id = ?";
} else {
    $sql = "UPDATE tasks SET completed = 0, completed_at = NULL WHERE id = ?";
}
$u = $conn->prepare($sql);
$u->bind_param("i", $task_id);
if ($u->execute()) {
    echo json_encode(['success' => true, 'completed' => $completed]);
} else {
    echo json_encode(['success' => false, 'msg' => 'DB update failed']);
}
$u->close();
