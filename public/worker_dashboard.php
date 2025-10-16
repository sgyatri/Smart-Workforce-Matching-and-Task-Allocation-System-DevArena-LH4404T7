<?php
// public/worker_dashboard.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';
require_worker();

$worker_id = $_SESSION['worker_id'];
$messages = [];

// Handle adding skill
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_skill') {
    $skillName = trim($_POST['skill'] ?? '');
    $prof = intval($_POST['proficiency'] ?? 3);
    if ($skillName !== '') {
        // ensure skill exists
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
        // check not already added
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
}

// Handle add certification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_cert') {
    $title = trim($_POST['title'] ?? '');
    $issuer = trim($_POST['issuer'] ?? '');
    $year = intval($_POST['year'] ?? 0);
    if ($title !== '') {
        $ins = $pdo->prepare("INSERT INTO certifications (worker_id, title, issuer, year) VALUES (?, ?, ?, ?)");
        $ins->execute([$worker_id, $title, $issuer, $year ?: null]);
        $messages[] = "Certification added.";
    }
}

// Fetch worker info
$stmt = $pdo->prepare("SELECT * FROM workers WHERE id = ?");
$stmt->execute([$worker_id]);
$worker = $stmt->fetch();

// Fetch skills
$skills = $pdo->prepare("SELECT ws.id, s.name, ws.proficiency FROM worker_skills ws JOIN skills s ON s.id = ws.skill_id WHERE ws.worker_id = ?");
$skills->execute([$worker_id]);
$skillsList = $skills->fetchAll();

// Fetch certifications
$certs = $pdo->prepare("SELECT * FROM certifications WHERE worker_id = ?");
$certs->execute([$worker_id]);
$certList = $certs->fetchAll();

// Fetch assignments
$assign = $pdo->prepare("SELECT a.*, j.title as job_title, m.name as manager_name FROM assignments a LEFT JOIN jobs j ON j.id = a.job_id LEFT JOIN managers m ON m.id = a.assigned_by_manager_id WHERE a.worker_id = ?");
$assign->execute([$worker_id]);
$assignments = $assign->fetchAll();
?>

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
          <ul class="list-group">
            <?php foreach ($assignments as $a): ?>
              <li class="list-group-item">
                <strong><?= esc($a['job_title'] ?? '—') ?></strong>
                <div class="small-muted">Status: <?= esc($a['status']) ?> / Assigned by: <?= esc($a['manager_name'] ?? '-') ?> <br>At: <?= esc($a['assigned_at']) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
