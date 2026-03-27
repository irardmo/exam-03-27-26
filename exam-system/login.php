<?php
require_once __DIR__ . '/auth.php';
include 'header.php';
?>

<div class="card login">
    <h2>Welcome Back</h2>
    <p>Sign in to continue</p>

    <?php if(!empty($error)): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" name="username" id="username" placeholder="Enter your username" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" placeholder="Enter your password" required>
        </div>
        <button type="submit" class="btn">Login</button>
    </form>

    <p>Don’t have an account? <a href="register.php">Create a new account</a></p>
</div>

<?php include 'footer.php'; ?>
