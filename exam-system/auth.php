<?php
// auth.php (Fixed)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
ensure_session(); // Assume this starts the session

// FIX 1: Initialize $error for use in the calling file (e.g., login.php)
$error = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';

    if ($u === '' || $p === '') {
        $error = "Please enter both username and password.";
    } else {
        // SQL query remains secure and correct
        $stmt = $conn->prepare("SELECT id, name, username, password_hash, role FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $u);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close(); // Close statement here

        if ($user && password_verify($p, $user['password_hash'])) {
            // Store user info in session
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'username' => $user['username'],
                'role' => $user['role']
            ];

            // Redirect based on role
            if ($user['role'] === 'admin') {
                redirect('admin.php');
            } elseif ($user['role'] === 'teacher') {
                redirect('teacher_dashboard.php');
            } elseif ($user['role'] === 'student') {
                redirect('student_dashboard.php');
            } else {
                $error = "Unknown user role.";
            }
            exit; // Ensure script stops after redirect
        } else {
            $error = "Invalid credentials.";
        }
    }
}

// FIX 3: It is highly recommended to move this logout logic to a separate logout.php file.
if (isset($_GET['logout'])) {
    // If you used $_SESSION['user'], session_destroy() is correct to clear everything.
    session_destroy();
    redirect('login.php');
    exit;
}