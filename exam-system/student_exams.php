<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_login();
require_role('student'); // Only students can access

// Fetch all exams
$exams = $conn->query("SELECT * FROM exams");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Available Exams</title>
  <link rel="stylesheet" href="style-all.css">
</head>
<body>
<h2>Available Exams</h2>
<table border="1" cellpadding="10">
    <tr>
        <th>Exam Title</th>
        <th>Action</th>
    </tr>
    <?php while ($exam = $exams->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($exam['title']); ?></td>
            <td><a href="take_exam.php?exam_id=<?php echo $exam['id']; ?>">Take Exam</a></td>
        </tr>
    <?php endwhile; ?>
</table>
</body>
</html>
