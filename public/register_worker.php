<?php
// public/register_worker.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $qual = trim($_POST['qualifications'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $errors[] = "Name, email and password are required.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email.";
    }

    if (empty($errors)) {
        // check duplicate email
        $stmt = $pdo->prepare("SELECT id FROM workers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = "Email already registered.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO workers (name, email, password, phone, qualifications) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$name, $email, $hash, $phone, $qual]);
            $success = true;
        }
    }
}
?>

<div class="row">
  <div class="col-md-6 offset-md-3">
    <h3>Worker Registration</h3>

    <?php if ($success): ?>
      <div class="alert alert-success">Registration successful. <a href="login.php">Login now</a>.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $e) echo "<div>".esc($e)."</div>"; ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label">Full name</label>
        <input name="name" class="form-control" value="<?= esc($_POST['name'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input name="email" type="email" class="form-control" value="<?= esc($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input name="password" type="password" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Phone</label>
        <input name="phone" class="form-control" value="<?= esc($_POST['phone'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Qualifications</label>
        <textarea name="qualifications" class="form-control"><?= esc($_POST['qualifications'] ?? '') ?></textarea>
      </div>
      <button class="btn btn-primary">Register</button>
    </form>
    <hr>
    <p class="small-muted">Already registered? <a href="login.php">Login here</a>.</p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
