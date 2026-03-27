<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
include 'header.php';

require_login();
require_role('student');

$user_id = $_SESSION['user']['id'];
$message = '';

// --- HANDLE PROFILE UPDATES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    
    $stmt = $conn->prepare("UPDATE students SET first_name = ?, last_name = ? WHERE user_id = ?");
    $stmt->bind_param('ssi', $first_name, $last_name, $user_id);
    if ($stmt->execute()) {
        $message = "✅ Profile updated successfully!";
    }
}

// --- HANDLE PASSWORD CHANGE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    if ($new_pass === $confirm_pass) {
        $hash = password_hash($new_pass, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $hash, $user_id);
        if ($stmt->execute()) {
            $message = "✅ Password changed successfully!";
        }
    } else {
        $message = "❌ Passwords do not match!";
    }
}

// --- HANDLE FILE UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['student_resource_file'])) {
    $target_dir = "uploads/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    $file_name = time() . "_" . basename($_FILES["student_resource_file"]["name"]);
    $target_file = $target_dir . $file_name;
    $ext = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];

    if (in_array($ext, $allowed)) {
        if (move_uploaded_file($_FILES["student_resource_file"]["tmp_name"], $target_file)) {
            $message = "✅ File uploaded successfully!";
        }
    } else {
        $message = "❌ Error: File type not allowed.";
    }
}

// Fetch current student info
$stmt_info = $conn->prepare("SELECT first_name, last_name FROM students WHERE user_id = ?");
$stmt_info->bind_param('i', $user_id);
$stmt_info->execute();
$student_info = $stmt_info->get_result()->fetch_assoc();

// Fetch Exams with Period (Description)
$query = "
    SELECT e.id AS exam_id, e.title, e.description AS period,
           a.id AS attempt_id,
           a.raw_score AS score,
           a.max_score,
           a.submitted_at AS last_submission
    FROM exams e
    LEFT JOIN attempts a
      ON e.id = a.exam_id
      AND a.student_id = ?
      AND a.id = (
           SELECT id FROM attempts
           WHERE exam_id = e.id AND student_id = ?
           ORDER BY submitted_at DESC LIMIT 1
      )
    WHERE e.is_active = 1
    ORDER BY e.id DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<style>
    /* Professional Styling & Mobile Responsiveness */
    .period-badge {
        background: #f1f5f9;
        color: #475569;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        border: 1px solid #e2e8f0;
        text-transform: uppercase;
        display: inline-block;
    }

    .student-resource-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 20px;
    }

    @media (max-width: 768px) {
        .profile-grid { grid-template-columns: 1fr !important; }
        .table thead { display: none; }
        .table tr { display: block; margin-bottom: 15px; border: 1px solid #eee; border-radius: 8px; padding: 10px; }
        .table td { display: flex; justify-content: space-between; align-items: center; border: none; padding: 5px 0; }
        .table td::before { content: attr(data-label); font-weight: bold; color: #64748b; }
    }
</style>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Welcome, <?= h($_SESSION['user']['username']); ?></h2>
        <span class="badge" style="background: #3498db; color: white;">Student Portal</span>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info" style="margin-bottom: 20px;"><?= $message ?></div>
    <?php endif; ?>

    <div class="tab-container">
        <button class="tab active" data-tab-target="exams">Exams</button>
        <button class="tab" data-tab-target="resources">Resources</button>
        <button class="tab" data-tab-target="profile">Settings</button>
        
    </div>

    <div id="exams" class="tab-content active">
        <h3>Available Exams & Results</h3>
        <?php if ($result->num_rows > 0): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Exam Title</th>
                        <th>Period</th>
                        <th>Score</th>
                        <th>Last Submission</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): 
                        $score = $row['score'] ?? 0; 
                        $total = $row['max_score'] ?? 0;
                        $is_failed = ($row['attempt_id'] && $total > 0 && (($score / $total) * 100) < 75);
                    ?>
                        <tr>
                            <td data-label="Title"><strong><?= h($row['title']); ?></strong></td>
                            <td data-label="Period">
                                <span class="period-badge"><?= h($row['period'] ?: 'N/A'); ?></span>
                            </td>
                            <td data-label="Score">
                                <?php if($row['attempt_id']): ?>
                                    <span style="color: <?= $is_failed ? '#e74c3c' : '#27ae60' ?>; font-weight:bold;">
                                        <?= $score; ?> / <?= $total; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="muted">No attempt</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Submitted"><?= $row['last_submission'] ? date('M d, Y', strtotime($row['last_submission'])) : 'N/A'; ?></td>
                            <td data-label="Action">
                                <div class="flex" style="gap:5px">
                                    <?php if (!$row['attempt_id']): ?>
                                        <a href="take_exam.php?exam_id=<?= $row['exam_id']; ?>" class="badge badge-success">Take Exam</a>
                                    <?php elseif ($is_failed): ?>
                                        <a href="take_exam.php?exam_id=<?= $row['exam_id']; ?>" class="badge" style="background:#e67e22; color:white;">Retake</a>
                                    <?php else: ?>
                                        <span class="badge" style="background:#bdc3c7; color:white;">Passed</span>
                                    <?php endif; ?>
                                    <?php if ($row['attempt_id']): ?>
                                        <a href="result.php?attempt_id=<?= $row['attempt_id']; ?>" class="badge">Result</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No exams available.</p>
        <?php endif; ?>
    </div>

    <div id="resources" class="tab-content">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
            <h3>Shared Resources</h3>
            <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 10px;">
                <input type="file" name="student_resource_file" required>
                <button type="submit" class="btn">Upload</button>
            </form>
        </div>

        <input type="text" id="imageSearch" placeholder="Search resources..." style="padding: 10px; border-radius: 5px; border: 1px solid #ccc; width: 100%; margin-bottom: 20px;">
        
        <div class="student-resource-grid" id="imageGrid">
            <?php
            $dir = "uploads/";
            if (is_dir($dir)) {
                $files = array_diff(scandir($dir), array('.', '..'));
                foreach ($files as $file):
                    $path = $dir . $file;
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            ?>
                <div class="image-card" data-name="<?= strtolower($file) ?>" style="background:#fff; border:1px solid #dee2e6; padding:15px; border-radius:10px; text-align:center;">
                    <?php if ($isImage): ?>
                        <img src="<?= $path ?>" style="width:100%; height:120px; object-fit:cover; border-radius:5px; margin-bottom:10px;">
                    <?php else: ?>
                        <div style="height:120px; background:#f8fafc; display:flex; align-items:center; justify-content:center; border-radius:5px; margin-bottom:10px; font-weight:bold;">.<?= strtoupper($ext) ?></div>
                    <?php endif; ?>
                    <div style="font-size:12px; margin-bottom:10px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= h($file) ?></div>
                    <a href="<?= $path ?>" download class="btn" style="width:100%; padding:5px; font-size:12px; display:block; text-decoration:none;">Download</a>
                </div>
            <?php endforeach; } ?>
        </div>
    </div>

    <div id="profile" class="tab-content">
        <div class="profile-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <form method="POST">
                <input type="hidden" name="update_profile" value="1">
                <h3>Edit Profile</h3>
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" value="<?= h($student_info['first_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" value="<?= h($student_info['last_name'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn">Save Changes</button>
            </form>

            <form method="POST">
                <input type="hidden" name="change_password" value="1">
                <h3>Security</h3>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required minlength="4">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required minlength="4">
                </div>
                <button type="submit" class="btn" style="background:#e67e22;">Update Password</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Persistent Tabs
    const savedTab = localStorage.getItem('activeStudentTab') || 'exams';
    document.querySelectorAll('.tab, .tab-content').forEach(el => el.classList.remove('active'));
    document.querySelector(`[data-tab-target="${savedTab}"]`)?.classList.add('active');
    document.getElementById(savedTab)?.classList.add('active');

    document.querySelectorAll('.tab').forEach(btn => {
        btn.onclick = () => {
            document.querySelectorAll('.tab, .tab-content').forEach(el => el.classList.remove('active'));
            btn.classList.add('active');
            const target = btn.dataset.tabTarget;
            document.getElementById(target).classList.add('active');
            localStorage.setItem('activeStudentTab', target);
        }
    });

    // Resource Filter
    const searchInput = document.getElementById('imageSearch');
    searchInput?.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('.image-card').forEach(card => {
            card.style.display = card.dataset.name.includes(filter) ? "" : "none";
        });
    });
});
</script>

<?php include 'footer.php'; ?>