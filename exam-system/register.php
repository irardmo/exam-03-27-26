<?php
// register.php (REVISED WITH TRANSACTION)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
include 'header.php';

$error = '';
$success = '';

// Initialize variables for sticky form (even though they are initialized above, 
// using the null coalescing operator in the HTML below handles this gracefully)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect all required fields
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_initial = trim($_POST['middle_initial'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $year_sec = trim($_POST['year_section'] ?? '');

           // 1. Validation Check
        if ($username === '' || $password === '' || $first_name === '' || $last_name === '' || $course === '' || $year_sec === '') {
            $error = "All mandatory fields are required.";
        } 
        // NEW: Check if course contains ONLY letters (and spaces if allowed)
        else if (!preg_match("/^[a-zA-Z\s]+$/", $course)) {
            $error = "Course must only contain letters (no numbers or special characters).";
        } 
        else {
        // 2. Check if username exists (SECURE)
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_check->bind_param("s", $username);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $error = "Username already exists!";
        } else {
            // --- START TRANSACTION ---
            $conn->begin_transaction();
            $success_flag = true;
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            // 3a. Insert into users table (Authentication)
            // FIX: Add 'name' column and its placeholder (?)
            $stmt_u = $conn->prepare("INSERT INTO users (name, username, password_hash, role, created_at) VALUES (?, ?, ?, 'student', NOW())");
            if ($stmt_u) {
                // FIX: Bind $first_name to the new 'name' placeholder
                $stmt_u->bind_param('sss', $first_name, $username, $hashed);
                
                if (!$stmt_u->execute()) $success_flag = false;
                $user_id = $conn->insert_id;
                $stmt_u->close();
            } else {
                $success_flag = false;
            }

            // 3b. Insert into students table (Profile)
            if ($success_flag) {
                $stmt_s = $conn->prepare("
                    INSERT INTO students (user_id, first_name, middle_initial, last_name, course, year_section)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                if ($stmt_s) {
                    $stmt_s->bind_param('isssss', $user_id, $first_name, $middle_initial, $last_name, $course, $year_sec);
                    if (!$stmt_s->execute()) $success_flag = false;
                    $stmt_s->close();
                } else {
                    $success_flag = false;
                }
            }

            // 4. Finalize transaction
            if ($success_flag) {
                $conn->commit();
                $success = "Registration successful! You can now login.";
            } else {
                $conn->rollback();
                // Use a generic error message for security, logging the database error separately if needed
                $error = "Registration failed due to a database error."; 
            }
        }
        $stmt_check->close();
    }
}
// Removed redundant variable definitions for brevity, use htmlspecialchars() on values below

?>
<div class="card login">
    <h2>Create Account</h2>
    <p>Student Registration</p>
    <?php if(!empty($error)): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if(!empty($success)): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?> <a href="login.php">Click here to log in.</a></div>
    <?php endif; ?>

   <form method="post">
        <div class="form-group">
            <label for="username">Username</label>
            <input id="username" name="username" required value="<?= htmlspecialchars($username ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="password">Password</label><input id="password" type="password" name="password" required></div>
        <hr>
        <div class="form-group">
            <label for="first_name">First Name</label>
            <input id="first_name" name="first_name" required value="<?= htmlspecialchars($first_name ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="middle_initial">Middle Initial </label>
            <input id="middle_initial" name="middle_initial" placeholder="M.I." value="<?= htmlspecialchars($middle_initial ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="last_name">Last Name</label>
            <input id="last_name" name="last_name" required value="<?= htmlspecialchars($last_name ?? '') ?>">
        </div>
        <hr>
        <div class="form-group">
    <label for="course">Course</label>
    <input 
        id="course" 
        name="course" 
        placeholder="e.g. BSIT" 
        required 
        value="<?= htmlspecialchars($course ?? '') ?>"
        pattern="[A-Za-z\s]+" 
        title="Course should only contain letters."
        oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
    >
</div>
        <div class="form-group">
            <label for="year_section">Year & Section</label>
            <input id="year_section" name="year_section" placeholder="e.g. 3A" required value="<?= htmlspecialchars($year_sec ?? '') ?>">
        </div>
        <button type="submit" class="btn">Create Account</button>
    </form>

    <p style="margin-top: 15px;">Already have an account? <a href="login.php">Login</a></p>
</div>
<?php include 'footer.php'; ?>