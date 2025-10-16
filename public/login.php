<?php
// public/login.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = "Email and password required.";
    } else {
        $stmt = $pdo->prepare("SELECT id, password, name FROM workers WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['worker_id'] = $row['id'];
            $_SESSION['worker_name'] = $row['name'];
            header('Location: worker_dashboard.php');
            exit;
        } else {
            $errors[] = "Invalid credentials.";
        }
    }
}
?>

<div class="row">
  <div class="col-md-5 offset-md-3">
    <h3>Worker Login</h3>

    <?php if ($errors): ?>
      <div class="alert alert-danger"><?php foreach ($errors as $e) echo "<div>".esc($e)."</div>"; ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input name="email" type="email" class="form-control" value="<?= esc($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input name="password" type="password" class="form-control" required>
      </div>
      <button class="btn btn-primary">Login</button>
    </form>
    <hr>
    <p class="small-muted">Manager? <a href="manager_login.php">Login as manager</a></p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
