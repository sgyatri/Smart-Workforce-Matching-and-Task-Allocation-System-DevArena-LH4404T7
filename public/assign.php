<?php
// public/assign.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_manager();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manager_dashboard.php');
    exit;
}

$job_id = intval($_POST['job_id'] ?? 0);
$worker_id = intval($_POST['worker_id'] ?? 0);
$manager_id = $_SESSION['manager_id'] ?? null;

if (!$job_id || !$worker_id) {
    $_SESSION['flash'] = "Invalid job or worker selection.";
    header('Location: manager_dashboard.php');
    exit;
}

// Optionally check if assignment exists already for this job/worker
$chk = $pdo->prepare("SELECT id FROM assignments WHERE worker_id = ? AND job_id = ? AND status = 'active'");
$chk->execute([$worker_id, $job_id]);
if ($chk->fetch()) {
    $_SESSION['flash'] = "This worker is already assigned to that job.";
    header('Location: manager_dashboard.php?job=' . $job_id);
    exit;
}

$ins = $pdo->prepare("INSERT INTO assignments (worker_id, job_id, assigned_by_manager_id, status) VALUES (?, ?, ?, 'active')");
$ins->execute([$worker_id, $job_id, $manager_id]);

$_SESSION['flash'] = "Worker assigned.";
header('Location: manager_dashboard.php?job=' . $job_id);
exit;
