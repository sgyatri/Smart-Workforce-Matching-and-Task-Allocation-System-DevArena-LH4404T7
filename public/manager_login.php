<?php
// public/manager_login.php
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
        $stmt = $pdo->prepare("SELECT id, password, name FROM managers WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['manager_id'] = $row['id'];
            $_SESSION['manager_name'] = $row['name'];
            header('Location: manager_dashboard.php');
            exit;
        } else {
            $errors[] = "Invalid manager credentials.";
        }
    }
}

// Note: if you don't have a manager, create one manually in phpMyAdmin or run a small PHP script to hash a password and insert.
// For convenience show a hint if no managers exist:
$mgrCount = $pdo->query("SELECT COUNT(*) as c FROM managers")->fetchColumn();
?>

<div class="row">
  <div class="col-md-5 offset-md-3">
    <h3>Manager Login</h3>
    <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo esc($e).'<br>'; ?></div><?php endif; ?>

    <form method="post">
      <div class="mb-3"><label class="form-label">Email</label><input name="email" type="email" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">Password</label><input name="password" type="password" class="form-control" required></div>
      <button class="btn btn-primary">Login</button>
    </form>

    <?php if ($mgrCount == 0): ?>
      <hr>
      <div class="alert alert-warning small-muted">
        No manager accounts currently exist. Create one in phpMyAdmin table <code>managers</code>.
        Example SQL to create a manager (replace <code>HASHED_PASSWORD</code> with output of password_hash):
        <pre>INSERT INTO managers (name,email,password) VALUES ('Admin','admin@example.com','HASHED_PASSWORD');</pre>
        To get hashed password quickly, create a file with <code>&lt;?php echo password_hash('yourpass',PASSWORD_DEFAULT);</code> and run it then paste the string in the SQL above.
      </div>
    <?php endif; ?>

    <hr>
    <p class="small-muted">Workers: <a href="login.php">Worker login</a> or <a href="register_worker.php">Register</a></p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
