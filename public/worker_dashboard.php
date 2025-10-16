<?php
// public/worker_dashboard.php
// JSON AJAX handler must run BEFORE any HTML output (header.php etc.)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// ensure session available for CSRF/session checks
if (session_status() === PHP_SESSION_NONE) session_start();

// helper to get all request headers in environments without getallheaders()
if (!function_exists('getAllHeadersCompat')) {
    function getAllHeadersCompat() {
        if (function_exists('getallheaders')) return getallheaders();
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }
} else {
    function getAllHeadersCompat() { return getallheaders(); }
}

// CSRF token ensure (create token if missing)
if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    catch (Exception $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}

// ---------- JSON handler (AJAX) ----------
$raw = file_get_contents('php://input');
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false && !empty($raw)) {
    header('Content-Type: application/json');

    // must be logged in as worker
    if (!isset($_SESSION['worker_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
        exit;
    }

    // CSRF check via header
    $headers = getAllHeadersCompat();
    $csrf_header = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? null;
    if (empty($csrf_header) || !hash_equals($_SESSION['csrf_token'], $csrf_header)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }

    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'msg' => 'Invalid JSON']);
        exit;
    }

    // Toggle assignment complete (expects assignment_id, completed)
    if (isset($input['assignment_id'])) {
        $worker_id = intval($_SESSION['worker_id']);
        $assignment_id = intval($input['assignment_id']);
        $completed = isset($input['completed']) && intval($input['completed']) ? 1 : 0;

        if ($assignment_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'msg' => 'Invalid assignment id']);
            exit;
        }

        // ensure assignment belongs to this worker (also fetch manager id)
        $q = $pdo->prepare("SELECT id, assigned_by_manager_id FROM assignments WHERE id = ? AND worker_id = ?");
        $q->execute([$assignment_id, $worker_id]);
        $assignmentRow = $q->fetch(PDO::FETCH_ASSOC);
        if (!$assignmentRow) {
            http_response_code(403);
            echo json_encode(['success' => false, 'msg' => 'Assignment not found or not assigned to you']);
            exit;
        }
        $manager_id = intval($assignmentRow['assigned_by_manager_id'] ?? 0);

        // Update status and completed_at if exists
        if ($completed) {
            try {
                $u = $pdo->prepare("UPDATE assignments SET status = 'completed', completed_at = NOW() WHERE id = ? AND worker_id = ?");
                $ok = $u->execute([$assignment_id, $worker_id]);
            } catch (PDOException $e) {
                // fallback if column completed_at doesn't exist
                $u = $pdo->prepare("UPDATE assignments SET status = 'completed' WHERE id = ? AND worker_id = ?");
                $ok = $u->execute([$assignment_id, $worker_id]);
            }
        } else {
            try {
                $u = $pdo->prepare("UPDATE assignments SET status = 'active', completed_at = NULL WHERE id = ? AND worker_id = ?");
                $ok = $u->execute([$assignment_id, $worker_id]);
            } catch (PDOException $e) {
                $u = $pdo->prepare("UPDATE assignments SET status = 'active' WHERE id = ? AND worker_id = ?");
                $ok = $u->execute([$assignment_id, $worker_id]);
            }
        }

        if ($ok) {
            // prepare human-friendly message
            $actionText = $completed ? 'completed' : 'reopened';
            $jobTitle = null;
            try {
                $jr = $pdo->prepare("SELECT j.title FROM jobs j JOIN assignments a ON a.job_id = j.id WHERE a.id = ? LIMIT 1");
                $jr->execute([$assignment_id]);
                $jrow = $jr->fetch(PDO::FETCH_ASSOC);
                if ($jrow) $jobTitle = $jrow['title'];
            } catch (Exception $e) { /* ignore */ }

            $message = sprintf(
                "Worker #%d marked assignment #%d%s %s",
                $worker_id,
                $assignment_id,
                $jobTitle ? " ({$jobTitle})" : "",
                $completed ? "as completed" : "as incomplete"
            );

            // Insert notification for manager if manager exists
            if ($manager_id > 0) {
                try {
                    $n = $pdo->prepare("INSERT INTO notifications (manager_id, worker_id, assignment_id, message) VALUES (?, ?, ?, ?)");
                    $n->execute([$manager_id, $worker_id, $assignment_id, $message]);
                } catch (Exception $e) {
                    // don't break the response if notifications table missing; log or ignore
                }
            }

            // return completed_at if exists
            $completedAt = null;
            try {
                $r = $pdo->prepare("SELECT completed_at FROM assignments WHERE id = ? LIMIT 1");
                $r->execute([$assignment_id]);
                $row = $r->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['completed_at'])) {
                    $completedAt = date('d M Y H:i', strtotime($row['completed_at']));
                }
            } catch (Exception $e) { /* ignore */ }

            echo json_encode(['success' => true, 'assignment_id' => $assignment_id, 'completed' => $completed, 'completed_at' => $completedAt]);
            exit;
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'msg' => 'DB update failed']);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => 'Unknown JSON action']);
    exit;
}
// ---------- end JSON handler ----------

// Normal page request continues here (HTML)
require_once __DIR__ . '/../includes/header.php';
require_worker();

$worker_id = $_SESSION['worker_id'];
$messages = [];

// Regular POST handlers for skills / certifications (form posts, not JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_skill') {
    $skillName = trim($_POST['skill'] ?? '');
    $prof = intval($_POST['proficiency'] ?? 3);
    if ($skillName !== '') {
        $st = $pdo->prepare("SELECT id FROM skills WHERE name = ?");
        $st->execute([$skillName]);
        $s = $st->fetch();
        if (!$s) {
            $ins = $pdo->prepare("INSERT INTO skills (name) VALUES (?)");
            $ins->execute([$skillName]);
            $skillId = $pdo->lastInsertId();
        } else {
            $skillId = $s['id'];
        }
        $chk = $pdo->prepare("SELECT id FROM worker_skills WHERE worker_id = ? AND skill_id = ?");
        $chk->execute([$worker_id, $skillId]);
        if ($chk->fetch()) {
            $messages[] = "Skill already added.";
        } else {
            $in = $pdo->prepare("INSERT INTO worker_skills (worker_id, skill_id, proficiency) VALUES (?, ?, ?)");
            $in->execute([$worker_id, $skillId, $prof]);
            $messages[] = "Skill added.";
        }
    }
    // redirect to avoid resubmission
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_cert') {
    $title = trim($_POST['title'] ?? '');
    $issuer = trim($_POST['issuer'] ?? '');
    $year = intval($_POST['year'] ?? 0);
    if ($title !== '') {
        $ins = $pdo->prepare("INSERT INTO certifications (worker_id, title, issuer, year) VALUES (?, ?, ?, ?)");
        $ins->execute([$worker_id, $title, $issuer, $year ?: null]);
        $messages[] = "Certification added.";
    }
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ---------------- Page data fetch ----------------
$stmt = $pdo->prepare("SELECT * FROM workers WHERE id = ?");
$stmt->execute([$worker_id]);
$worker = $stmt->fetch();

$skills = $pdo->prepare("SELECT ws.id, s.name, ws.proficiency FROM worker_skills ws JOIN skills s ON s.id = ws.skill_id WHERE ws.worker_id = ?");
$skills->execute([$worker_id]);
$skillsList = $skills->fetchAll();

$certs = $pdo->prepare("SELECT * FROM certifications WHERE worker_id = ?");
$certs->execute([$worker_id]);
$certList = $certs->fetchAll();

// Fetch assignments
$assign = $pdo->prepare("
  SELECT a.*, j.title as job_title, m.name as manager_name
  FROM assignments a
  LEFT JOIN jobs j ON j.id = a.job_id
  LEFT JOIN managers m ON m.id = a.assigned_by_manager_id
  WHERE a.worker_id = ?
  ORDER BY a.assigned_at DESC
");
$assign->execute([$worker_id]);
$assignments = $assign->fetchAll(PDO::FETCH_ASSOC);

// Preload tasks for assignments (optional)
$assignmentIds = array_column($assignments, 'id');
$tasksByAssignment = [];
if (!empty($assignmentIds)) {
    $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
    $sql = "SELECT * FROM tasks WHERE assignment_id IN ($placeholders) ORDER BY created_at ASC";
    $st = $pdo->prepare($sql);
    $st->execute($assignmentIds);
    $allTasks = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allTasks as $t) {
        $tasksByAssignment[$t['assignment_id']][] = $t;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Worker Dashboard</title>
  <style>
    .assignment-box { background: #fff; }
    .assignment-box .check-item { transition: background .12s, border-color .12s; }
    .assignment-box .check-item:hover { background: #f8f9fa; border-color: #e9ecef; }
    .assignment-box .check-item input.task-checkbox { width:18px; height:18px; }
    .assignment-box .check-item.checked { background: #f2fff0; border-color: #d9f7d9; }
    .assignment-completed-badge { font-size:0.85rem; color: #155724; background: #d4edda; padding: 4px 8px; border-radius: 6px; }
  </style>
</head>
<body>
<div class="container mt-4">
  <div class="row">
    <div class="col-md-8">
      <h4>Welcome, <?= esc($_SESSION['worker_name']) ?></h4>

      <?php foreach ($messages as $m): ?>
        <div class="alert alert-info"><?= esc($m) ?></div>
      <?php endforeach; ?>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Profile</h5>
          <p><strong>Name:</strong> <?= esc($worker['name']) ?><br>
             <strong>Email:</strong> <?= esc($worker['email']) ?><br>
             <strong>Phone:</strong> <?= esc($worker['phone']) ?><br>
             <strong>Qualifications:</strong> <?= nl2br(esc($worker['qualifications'])) ?></p>
        </div>
      </div>

      <!-- Add Skill -->
      <div class="card mb-3">
        <div class="card-body">
          <h5>Add Skill</h5>
          <form method="post">
            <input type="hidden" name="action" value="add_skill">
            <div class="row">
              <div class="col-md-7 mb-2">
                <input name="skill" class="form-control" placeholder="Skill name (e.g. Welding)" required>
              </div>
              <div class="col-md-3 mb-2">
                <select name="proficiency" class="form-select">
                  <option value="1">1 - Low</option>
                  <option value="2">2</option>
                  <option value="3" selected>3 - Medium</option>
                  <option value="4">4</option>
                  <option value="5">5 - High</option>
                </select>
              </div>
              <div class="col-md-2 mb-2">
                <button class="btn btn-primary w-100">Add</button>
              </div>
            </div>
          </form>

          <hr>
          <h6>Your skills</h6>
          <?php if ($skillsList): ?>
            <ul class="list-group">
              <?php foreach ($skillsList as $s): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <?= esc($s['name']) ?> <span class="badge bg-secondary">Prof: <?= esc($s['proficiency']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="small-muted">No skills added yet.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Add Certification -->
      <div class="card mb-3">
        <div class="card-body">
          <h5>Add Certification</h5>
          <form method="post">
            <input type="hidden" name="action" value="add_cert">
            <div class="mb-2">
              <input name="title" class="form-control" placeholder="Certificate title (e.g. First Aid)">
            </div>
            <div class="mb-2">
              <input name="issuer" class="form-control" placeholder="Issuer (e.g. ABC Institute)">
            </div>
            <div class="mb-2">
              <input name="year" class="form-control" placeholder="Year (optional)">
            </div>
            <button class="btn btn-primary">Add Certification</button>
          </form>

          <hr>
          <h6>Your certifications</h6>
          <?php if ($certList): ?>
            <ul class="list-group">
              <?php foreach ($certList as $c): ?>
                <li class="list-group-item">
                  <strong><?= esc($c['title']) ?></strong>
                  <div class="small-muted"><?= esc($c['issuer']) ?> <?= $c['year'] ? '(' . esc($c['year']) . ')' : '' ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="small-muted">No certifications yet.</p>
          <?php endif; ?>

        </div>
      </div>

    </div>

    <div class="col-md-4">
      <div class="card mb-3">
        <div class="card-body">
          <h6>Your Assignments</h6>

          <?php if ($assignments): ?>
            <div class="assignments-list">
              <?php foreach ($assignments as $a): ?>
                <?php
                  $isCompleted = (strtolower($a['status'] ?? '') === 'completed');
                  $tasks = $tasksByAssignment[$a['id']] ?? [];
                ?>
                <div class="assignment-box mb-3 p-3 border rounded" data-assignment-id="<?= (int)$a['id'] ?>">
                  <div class="d-flex align-items-start">
                    <div style="flex:1;">
                      <strong class="fs-6"><?= esc($a['job_title'] ?? '—') ?></strong>
                      <div class="small text-muted">
                        Assigned by: <?= esc($a['manager_name'] ?? '-') ?> • At: <?= esc($a['assigned_at']) ?>
                      </div>
                    </div>

                    <div class="text-end">
                      <?php if ($isCompleted): ?>
                        <div class="assignment-completed-badge">Completed</div>
                      <?php endif; ?>
                      <div class="form-check mt-2">
                        <input class="form-check-input assignment-checkbox" type="checkbox" value="" id="assignChk<?= (int)$a['id'] ?>"
                          <?= $isCompleted ? 'checked' : '' ?> aria-label="Mark assignment complete">
                        <label class="form-check-label small text-muted" for="assignChk<?= (int)$a['id'] ?>">
                          Mark complete
                        </label>
                      </div>
                    </div>
                  </div>

                  <?php if (!empty($tasks)): ?>
                    <div class="mt-3 small">
                      <strong>Checklist:</strong>
                      <ul>
                        <?php foreach ($tasks as $t): ?>
                          <li><?= esc($t['title']) ?> <?= $t['completed'] ? '<span class="text-success">(done)</span>' : '' ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>

                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="small-muted">No assignments yet.</p>
          <?php endif; ?>

        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h6>Quick Links</h6>
          <a class="btn btn-sm btn-outline-primary mb-1" href="logout.php">Logout</a>
          <a class="btn btn-sm btn-outline-secondary mb-1" href="index.php">Home</a>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function(){
  const csrf = <?= json_encode($_SESSION['csrf_token']) ?>;

  // Toggle assignment completion via AJAX (POST JSON to same page)
  document.addEventListener('change', async (e) => {
    if (!e.target.classList.contains('assignment-checkbox')) return;
    const cb = e.target;
    const box = cb.closest('.assignment-box');
    const assignmentId = parseInt(box.dataset.assignmentId, 10);
    const completed = cb.checked ? 1 : 0;

    // optimistic UI
    if (completed) {
      let badge = box.querySelector('.assignment-completed-badge');
      if (!badge) {
        badge = document.createElement('div');
        badge.className = 'assignment-completed-badge';
        badge.textContent = 'Completed';
        box.querySelector('.text-end').insertBefore(badge, box.querySelector('.text-end').firstChild);
      }
    } else {
      const badge = box.querySelector('.assignment-completed-badge');
      if (badge) badge.remove();
    }
    cb.disabled = true;

    try {
      const res = await fetch(location.pathname + location.search, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf
        },
        body: JSON.stringify({ assignment_id: assignmentId, completed: completed })
      });

      if (!res.ok) {
        let msg = 'Server error: ' + res.status;
        try { const j = await res.json(); if (j && j.msg) msg = j.msg; } catch (err) {}
        throw new Error(msg);
      }
      const data = await res.json();
      if (!data.success) throw new Error(data.msg || 'Update failed');

      // success: optionally update completed timestamp somewhere if needed
    } catch (err) {
      // revert
      cb.checked = !cb.checked;
      if (!cb.checked) {
        const badge = box.querySelector('.assignment-completed-badge');
        if (badge) badge.remove();
      }
      alert(err.message || 'Network error');
    } finally {
      cb.disabled = false;
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>









