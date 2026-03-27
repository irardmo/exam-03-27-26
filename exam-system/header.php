<?php
// FIX: Check if a session has NOT been started before calling session_start().
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Helper variable for cleaner access to session data
$user = $_SESSION['user'] ?? null; // Create a user variable based on the correct session key
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exam System</title>
    <link rel="stylesheet" href="redesign.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="tabs.js" defer></script>
</head>
<body>
    <header>
        <div class="container">
            <h1><a href="index.php">Exam System</a></h1>
            <ul>
                <?php if ($user): // FIX: Check if the $user array exists (i.e., user is logged in) ?>
                    <?php if ($user['role'] === 'student'): // FIX: Use the role from the 'user' array ?>
                        <li><a href="student_dashboard.php">Dashboard</a></li>
                    <?php else: ?>
                        <li><a href="teacher_dashboard.php">Dashboard</a></li>
                    <?php endif; ?>
                    <li><a href="logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </header>
    <div class="main-content">
        <div class="container">
     
