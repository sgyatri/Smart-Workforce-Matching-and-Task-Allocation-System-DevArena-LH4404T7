<?php
// public/manager_dashboard.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';
require_manager();

$messages = [];

// Add job handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_job') {
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $skills = $_POST['skills'] ?? []; // expected array of skill names or ids
    $levels = $_POST['levels'] ?? [];

    if ($title === '') {
        $messages[] = "Job title required.";
    } else {
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO jobs (title, description) VALUES (?, ?)");
            $ins->execute([$title, $desc]);
            $jobId = $pdo->lastInsertId();

            // skills posted as comma-separated string of names from the form; we'll handle both
            // For simplicity we accept a set of skill names in a single input "skill_list" split by comma
            if (!empty($_POST['skill_list'])) {
                $skillList = array_map('trim', explode(',', $_POST['skill_list']));
                foreach ($skillList as $idx => $skillName) {
                    if ($skillName === '') continue;
                    // ensure skill exists
                    $st = $pdo->prepare("SELECT id FROM skills WHERE name = ?");
                    $st->execute([$skillName]);
                    $s = $st->fetch();
                    if (!$s) {
                        $pdo->prepare("INSERT INTO skills (name) VALUES (?)")->execute([$skillName]);
                        $skillId = $pdo->lastInsertId();
                    } else $skillId = $s['id'];

                    // required level from optional levels array or default 3
                    $required_level = intval($levels[$idx] ?? 3);
                    $pdo->prepare("INSERT INTO job_skills (job_id, skill_id, required_level) VALUES (?, ?, ?)")
                        ->execute([$jobId, $skillId, $required_level]);
                }
            }

            $pdo->commit();
            $messages[] = "Job created.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $messages[] = "Error creating job: " . $e->getMessage();
        }
    }
}

// Assignment action handled by assign.php (below) via POST

// Fetch jobs
$jobs = $pdo->query("SELECT * FROM jobs ORDER BY created_at DESC")->fetchAll();

// Fetch recent workers (for reference)
$workers = $pdo->query("SELECT id,name FROM workers ORDER BY name LIMIT 200")->fetchAll();
?>

<div class="row">
  <div class="col-md-8">
    <h4>Manager Dashboard — <?= esc($_SESSION['manager_name']) ?></h4>

    <?php foreach ($messages as $m): ?><div class="alert alert-info"><?= esc($m) ?></div><?php endforeach; ?>

    <div class="card mb-3">
      <div class="card-body">
        <h5>Create Job</h5>
        <form method="post">
          <input type="hidden" name="action" value="add_job">
          <div class="mb-2"><input name="title" class="form-control" placeholder="Job title (e.g. Site Welder)"></div>
          <div class="mb-2"><textarea name="description" class="form-control" placeholder="Description (optional)"></textarea></div>

          <div class="mb-2">
            <label class="form-label">Required skills (comma-separated)</label>
            <input name="skill_list" class="form-control" placeholder="e.g. Welding, Rigging, Safety">
            <div class="small-muted mt-1">Optional: after creating, you can edit job skills in DB or extend UI to have separate inputs</div>
          </div>

          <div class="mb-2">
            <label class="form-label">Optional proficiency levels (comma separated, same order) — default 3</label>
            <input name="levels[]" class="form-control" placeholder="e.g. 4,3,2">
            <div class="small-muted mt-1">If you enter fewer/none, default required_level = 3</div>
          </div>

          <button class="btn btn-success">Create Job</button>
        </form>
      </div>
    </div>

    <h5>Jobs</h5>
    <?php if ($jobs): ?>
      <?php foreach ($jobs as $job): ?>
        <div class="card mb-2">
          <div class="card-body">
            <h6><?= esc($job['title']) ?></h6>
            <p class="small-muted"><?= nl2br(esc($job['description'])) ?></p>

            <?php
            // fetch required skills for job
            $js = $pdo->prepare("SELECT js.required_level, s.name FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id = ?");
            $js->execute([$job['id']]);
            $reqs = $js->fetchAll();
            if ($reqs):
            ?>
              <div>
                <strong>Required skills:</strong>
                <?php foreach ($reqs as $r): ?>
                  <span class="badge bg-secondary"><?= esc($r['name']) ?> (L<?= esc($r['required_level']) ?>)</span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="small-muted">No required skills specified.</div>
            <?php endif; ?>

            <div class="mt-2">
              <a class="btn btn-sm btn-outline-primary" href="manager_dashboard.php?job=<?=$job['id']?>">View recommendations</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="small-muted">No jobs yet.</p>
    <?php endif; ?>
  </div>

  <div class="col-md-4">
    <?php
    // If job param present, show recommended workers
    if (!empty($_GET['job'])):
      $jobId = intval($_GET['job']);
      // Query to compute matched points as in plan; also handle jobs with no skills (will show none)
      $sql = "
      SELECT w.id, w.name,
        COALESCE(SUM(LEAST(ws.proficiency, js.required_level)),0) AS matched_points,
        COALESCE(SUM(js.required_level),0) AS total_required,
        (CASE WHEN COALESCE(SUM(js.required_level),0) = 0 THEN 0 ELSE (SUM(LEAST(ws.proficiency, js.required_level)) / SUM(js.required_level)) END) AS score
      FROM workers w
      LEFT JOIN worker_skills ws ON ws.worker_id = w.id
      LEFT JOIN job_skills js ON js.skill_id = ws.skill_id AND js.job_id = ?
      GROUP BY w.id
      ORDER BY score DESC, matched_points DESC
      LIMIT 30
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$jobId]);
      $candidates = $stmt->fetchAll();

      // fetch job title
      $jobtitle = $pdo->prepare("SELECT title FROM jobs WHERE id = ?");
      $jobtitle->execute([$jobId]);
      $jt = $jobtitle->fetchColumn();
    ?>
      <div class="card">
        <div class="card-body">
          <h6>Recommendations for: <?= esc($jt) ?></h6>
          <?php if ($candidates): ?>
            <form method="post" action="assign.php">
              <input type="hidden" name="job_id" value="<?= esc($jobId) ?>">
              <ul class="list-group mb-2">
                <?php foreach ($candidates as $cand): ?>
                  <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                      <strong><?= esc($cand['name']) ?></strong><br>
                      <div class="small-muted">Score: <?= number_format((float)$cand['score'], 2) ?> (<?= esc($cand['matched_points']) ?>/<?= esc($cand['total_required']) ?>)</div>
                    </div>
                    <div>
                      <input type="radio" name="worker_id" value="<?= esc($cand['id']) ?>" required>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
              <button class="btn btn-primary w-100">Assign selected worker</button>
            </form>
          <?php else: ?>
            <p class="small-muted">No candidates found.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-body">
          <h6>Quick tips</h6>
          <p class="small-muted">Click "View recommendations" on any job to see top matches based on worker skills & proficiency. Select a candidate and assign them to the job.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
