<?php
// admin.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_login(); 
require_role('admin');

$msg = $_SESSION['admin_msg'] ?? '';
unset($_SESSION['admin_msg']);

// --- SETTINGS ---
$limit = 100; 
$user_search = trim($_GET['usearch'] ?? '');
$exam_search = trim($_GET['esearch'] ?? '');
$user_page = isset($_GET['upage']) ? max(1, (int)$_GET['upage']) : 1;
$exam_page = isset($_GET['epage']) ? max(1, (int)$_GET['epage']) : 1;
$user_offset = ($user_page - 1) * $limit;
$exam_offset = ($exam_page - 1) * $limit;
$active_tab = $_GET['tab'] ?? 'user-tab';

// ----------------------------------------------------------------------
// 1. DATABASE LOGIC (POST HANDLERS)
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';
    $current_tab = $_POST['current_tab'] ?? 'user-tab';

    // CREATE USER / STUDENT
    if ($form === 'create_user' || $form === 'create_student') {
        $username = trim($_POST['username']); 
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $role = $_POST['role'] ?? 'student';

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?,?,?)");
            $stmt->bind_param('sss', $username, $password, $role);
            $stmt->execute();
            $new_id = $conn->insert_id;

            if ($form === 'create_student') {
                $f = trim($_POST['first_name']);
                $m = trim($_POST['middle_initial']);
                $l = trim($_POST['last_name']);
                $c = trim($_POST['course']);
                $y = trim($_POST['year_section']);
                $stmt_s = $conn->prepare("INSERT INTO students (user_id, first_name, middle_initial, last_name, course, year_section) VALUES (?,?,?,?,?,?)");
                $stmt_s->bind_param('isssss', $new_id, $f, $m, $l, $c, $y);
                $stmt_s->execute();
            }
            $conn->commit();
            $_SESSION['admin_msg'] = "✅ Account created successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['admin_msg'] = "❌ Error: " . $e->getMessage();
        }
    }

    // CREATE EXAM (RESTORED LOGIC)
    if ($form === 'create_exam') {
        $title = trim($_POST['title']);
        $desc = $_POST['description']; // Prelim, Midterm, etc.
        $tid = (int)$_POST['teacher_id'];
        
        $stmt = $conn->prepare("INSERT INTO exams (title, description, created_by, is_active) VALUES (?, ?, ?, 0)");
        $stmt->bind_param('ssi', $title, $desc, $tid);
        $_SESSION['admin_msg'] = $stmt->execute() ? "✅ Exam created successfully." : "❌ Error: " . $conn->error;
    }

    // RESET PASSWORD
    if ($form === 'reset_password') {
        $uid = (int)$_POST['user_id'];
        $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $hash, $uid);
        $_SESSION['admin_msg'] = $stmt->execute() ? "✅ Password reset successfully." : "❌ Error.";
    }

    // EDIT EXAM
    if ($form === 'edit_exam') {
        $eid = (int)$_POST['exam_id'];
        $title = trim($_POST['title']);
        $desc = $_POST['description'];
        $tid = (int)$_POST['teacher_id'];
        $stmt = $conn->prepare("UPDATE exams SET title=?, description=?, created_by=? WHERE id=?");
        $stmt->bind_param('ssii', $title, $desc, $tid, $eid);
        $_SESSION['admin_msg'] = $stmt->execute() ? "✅ Exam updated." : "❌ Error.";
    }

    // DELETE LOGIC
    if ($form === 'delete_user' || $form === 'delete_exam') {
        $table = ($form === 'delete_user') ? 'users' : 'exams';
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->bind_param('i', $id);
        $_SESSION['admin_msg'] = $stmt->execute() ? "🗑️ Deleted." : "❌ Error.";
    }

    redirect("admin.php?upage=$user_page&epage=$exam_page&usearch=$user_search&esearch=$exam_search&tab=$current_tab");
}

// ----------------------------------------------------------------------
// 2. DATA FETCHING
// ----------------------------------------------------------------------
$teacher_res = $conn->query("SELECT id, username FROM users WHERE role = 'teacher' ORDER BY username ASC");
$teachers = $teacher_res->fetch_all(MYSQLI_ASSOC);

$u_term = "%$user_search%";
$stmt_u = $conn->prepare("SELECT u.id, u.username, u.role, CONCAT(s.first_name, ' ', s.last_name) as student_name, s.course, s.year_section 
                          FROM users u LEFT JOIN students s ON u.id = s.user_id 
                          WHERE u.username LIKE ? ORDER BY u.id DESC LIMIT ? OFFSET ?");
$stmt_u->bind_param('sii', $u_term, $limit, $user_offset);
$stmt_u->execute();
$users_list = $stmt_u->get_result();

$counts_res = $conn->query("SELECT role, COUNT(*) as total FROM users GROUP BY role");
$stats = ['admin' => 0, 'teacher' => 0, 'student' => 0];
while($row = $counts_res->fetch_assoc()) { $stats[$row['role']] = $row['total']; }

$e_term = "%$exam_search%";
$stmt_e = $conn->prepare("SELECT e.*, u.username as teacher_name FROM exams e LEFT JOIN users u ON u.id = e.created_by WHERE e.title LIKE ? ORDER BY e.id DESC LIMIT ? OFFSET ?");
$stmt_e->bind_param('sii', $e_term, $limit, $exam_offset);
$stmt_e->execute();
$exams_list = $stmt_e->get_result();

$stats_query = "
    SELECT e.id, e.title, u.username as instructor, COUNT(a.id) as total_students,
    SUM(CASE WHEN (a.raw_score / a.max_score) * 100 >= 75 THEN 1 ELSE 0 END) as passed,
    SUM(CASE WHEN (a.raw_score / a.max_score) * 100 < 75 THEN 1 ELSE 0 END) as failed
    FROM exams e
    LEFT JOIN users u ON e.created_by = u.id
    LEFT JOIN attempts a ON e.id = a.exam_id
    GROUP BY e.id ORDER BY total_students DESC
";
$exam_stats = $conn->query($stats_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <style>
        :root { --sidebar: #1e1e2d; --primary: #4361ee; --bg: #f4f7fe; --text: #2b2d42; --gray: #8d99ae; --danger: #ef233c; --border: #edf2f4; }
        body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; margin: 0; display: flex; }
        .sidebar { width: 260px; background: var(--sidebar); height: 100vh; position: fixed; left: 0; top: 0; color: #fff; display: flex; flex-direction: column; z-index: 1000; }
        .sidebar-header { padding: 30px 20px; font-size: 20px; font-weight: 800; color: var(--primary); border-bottom: 1px solid rgba(255,255,255,0.05); }
        .nav-btn { width: 100%; padding: 15px 25px; border: none; background: transparent; color: #a2a3b7; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 12px; transition: 0.3s; font-weight: 500; }
        .nav-btn:hover { background: #2b2b40; color: #fff; }
        .nav-btn.active { background: var(--primary); color: #fff; border-left: 5px solid #fff; }
        .sub-menu { background: #161623; display: none; padding-bottom: 10px; }
        .sub-menu.show { display: block; }
        .sub-nav-btn { width: 100%; padding: 10px 25px 10px 55px; border: none; background: transparent; color: #888; text-align: left; cursor: pointer; font-size: 13px; transition: 0.2s; }
        .sub-nav-btn:hover { color: #fff; }
        .sidebar-footer { padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); margin-top: auto; }
        .btn-logout { background: var(--danger); color: white; padding: 12px; border-radius: 8px; text-decoration: none; display: block; text-align: center; font-weight: 600; font-size: 14px; }
        .main-content { margin-left: 260px; padding: 40px; width: calc(100% - 260px); box-sizing: border-box; min-height: 100vh; }
        .card { background: #fff; padding: 30px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid var(--border); }
        .admin-tab-content { display: none; }
        .admin-tab-content.active { display: block; animation: fadeIn 0.3s; }
        .form-section { background: #fafbfc; padding: 25px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 30px; display: none; }
        .form-section.active { display: block; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .group { display: flex; flex-direction: column; gap: 6px; }
        .group label { font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--gray); }
        input, select { padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .btn-main { background: var(--primary); color: #fff; border: none; padding: 12px 25px; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        .table td, .table th { padding: 15px; background: #fff; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .badge { padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; }
        .modal { display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
        .modal-content { background:white; margin: 10% auto; padding: 30px; width: 400px; border-radius: 16px; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; } }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-header">ADMIN PANEL</div>
    <div style="flex:1; padding-top:20px; overflow-y: auto;">
        <button id="btn-user-tab" class="nav-btn" onclick="toggleSubMenu('user-submenu', 'user-tab')"><span>👥</span> Manage Users</button>
        <div id="user-submenu" class="sub-menu">
            <button class="sub-nav-btn" onclick="showUserForm('add-user-form', 'staff')">+ Add Instructor/Admin</button>
            <button class="sub-nav-btn" onclick="showUserForm('add-student-form', 'student')">+ Add Student</button>
        </div>
        <button id="btn-exam-tab" class="nav-btn" onclick="showTab('exam-tab')"><span>📝</span> Manage Exams</button>
        <button id="btn-stats-tab" class="nav-btn" onclick="showTab('stats-tab')"><span>📊</span> Exam Statistics</button>
    </div>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn-logout">Logout System</a>
    </div>
</aside>

<main class="main-content">
    <div class="card">
        <?php if($msg): ?><div style="background:#e3f2fd; color:#0d47a1; padding:15px; border-radius:8px; margin-bottom:20px; font-weight:600;"><?= h($msg) ?></div><?php endif; ?>

        <div id="user-tab" class="admin-tab-content">
            <h2 id="user-title">User Management</h2>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
                <div style="background: #fff; padding: 20px; border-radius: 12px; border: 1px solid var(--border); text-align: center;">
                    <div style="color: var(--gray); font-size: 12px; font-weight: 700; text-transform: uppercase;">Total Students</div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--primary);"><?= $stats['student'] ?></div>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 12px; border: 1px solid var(--border); text-align: center;">
                    <div style="color: var(--gray); font-size: 12px; font-weight: 700; text-transform: uppercase;">Total Teachers</div>
                    <div style="font-size: 24px; font-weight: 800; color: #9b59b6;"><?= $stats['teacher'] ?></div>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 12px; border: 1px solid var(--border); text-align: center;">
                    <div style="color: var(--gray); font-size: 12px; font-weight: 700; text-transform: uppercase;">Total Admins</div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--text);"><?= $stats['admin'] ?></div>
                </div>
            </div>

            <div id="add-user-form" class="form-section">
                <h3>Create Instructor</h3>
                <form method="post"><input type="hidden" name="form" value="create_user"><input type="hidden" name="current_tab" value="user-tab">
                    <div class="form-grid">
                        <div class="group"><label>Username</label><input name="username" required></div>
                        <div class="group"><label>Password</label><input type="password" name="password" required></div>
                        <div class="group"><label>Role</label><select name="role"><option value="teacher">Teacher</option><option value="admin">Admin</option></select></div>
                    </div>
                    <button type="submit" class="btn-main">Create Instructor</button>
                </form>
            </div>

            <div id="add-student-form" class="form-section">
                <h3>Create Student</h3>
                <form method="post"><input type="hidden" name="form" value="create_student"><input type="hidden" name="current_tab" value="user-tab">
                    <div class="form-grid">
                        <div class="group"><label>Username</label><input name="username" required></div>
                        <div class="group"><label>Password</label><input type="password" name="password" required></div>
                    </div>
                    <div class="form-grid">
                        <div class="group"><label>First Name</label><input name="first_name" required></div>
                        <div class="group"><label>M.I.</label><input name="middle_initial" maxlength="2"></div>
                        <div class="group"><label>Last Name</label><input name="last_name" required></div>
                    </div>
                    <div class="form-grid">
                        <div class="group"><label>Course</label><input name="course" required></div>
                        <div class="group"><label>Year & Section</label><input name="year_section" required></div>
                    </div>
                    <button type="submit" class="btn-main">Create Student</button>
                </form>
            </div>

            <table class="table" id="userTable">
                <?php while($u = $users_list->fetch_assoc()): ?>
                <tr data-role="<?= h($u['role']) ?>">
                    <td>
                        <strong><?= $u['student_name'] ? h($u['student_name']) : h($u['username']) ?></strong>
                        <br><small style="color:var(--gray)"><?= h($u['username']) ?></small>
                    </td>
                    <td><span class="badge" style="background:#eef2ff; color:var(--primary);"><?= strtoupper($u['role']) ?></span></td>
                    <td style="text-align:right">
                        <button class="badge" style="background:orange; color:white;" onclick="openResetModal(<?= $u['id'] ?>, '<?= h($u['username']) ?>')">Reset Password</button>
                        <button class="badge" style="background:var(--danger); color:white;" onclick="confirmDelete(<?= $u['id'] ?>, 'user')">Delete</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>

        <div id="exam-tab" class="admin-tab-content">
            <h2>Exam Management</h2>
            <form method="post" class="form-grid" style="background:#fafbfc; padding:20px; border-radius:12px;">
                <input type="hidden" name="form" value="create_exam"><input type="hidden" name="current_tab" value="exam-tab">
                <div class="group"><label>Title</label><input name="title" required></div>
                <div class="group"><label>Category</label>
                    <select name="description">
                        <option value="Prelim Exam">Prelim Exam</option>
                        <option value="Midterm Exam">Midterm Exam</option>
                        <option value="Final Exam">Final Exam</option>
                        <option value="Quiz">Quiz</option>
                        <option value="Activity">Activity</option>
                        <option value="Long Quiz">Long Quiz</option>
                        <option value="Recitation">Recitation</option>
                    </select>
                </div>
                <div class="group"><label>Teacher</label>
                    <select name="teacher_id">
                        <?php foreach($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['username']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-main" style="margin-top:18px;">Create</button>
            </form>
            <table class="table">
                <?php while($e = $exams_list->fetch_assoc()): ?>
                <tr>
                    <td><strong><?= h($e['title']) ?></strong></td>
                    <td><?= h($e['description']) ?></td>
                    <td><?= h($e['teacher_name']) ?></td>
                    <td style="text-align:right">
                        <button class="badge" style="background:var(--primary); color:white;" onclick="openEditModal(<?= $e['id'] ?>, '<?= h($e['title']) ?>', '<?= h($e['description']) ?>', <?= $e['created_by'] ?>)">Edit</button>
                        <button class="badge" style="background:var(--danger); color:white;" onclick="confirmDelete(<?= $e['id'] ?>, 'exam')">Delete</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>

        <div id="stats-tab" class="admin-tab-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h2>Exam Performance Statistics</h2>
                <button onclick="window.print()" class="badge" style="background:var(--sidebar); color:white; padding:10px 20px;">🖨️ Print Report</button>
            </div>
            <table class="table">
                <thead>
                    <tr style="text-align: left; background: #f8f9fa;">
                        <th style="padding:15px;">Subject / Exam Title</th>
                        <th>Instructor</th>
                        <th style="text-align:center;">Students Took Exam</th>
                        <th style="text-align:center;">Passed (≥75%)</th>
                        <th style="text-align:center;">Failed</th>
                        <th style="text-align:right; padding-right:15px;">Success Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($exam_stats && $exam_stats->num_rows > 0): ?>
                        <?php while($row = $exam_stats->fetch_assoc()): 
                            $rate = $row['total_students'] > 0 ? round(($row['passed'] / $row['total_students']) * 100, 1) : 0;
                            $color = ($rate >= 75) ? '#2ecc71' : ($rate >= 50 ? '#f1c40f' : '#ef233c');
                        ?>
                        <tr>
                            <td style="padding:15px;"><strong><?= h($row['title']) ?></strong></td>
                            <td><small><?= h($row['instructor']) ?></small></td>
                            <td style="text-align:center;"><?= $row['total_students'] ?></td>
                            <td style="text-align:center; color:#2ecc71; font-weight:700;"><?= $row['passed'] ?></td>
                            <td style="text-align:center; color:#ef233c; font-weight:700;"><?= $row['failed'] ?></td>
                            <td style="text-align:right; padding-right:15px;">
                                <div style="display:inline-block; width:50px; background:#eee; height:8px; border-radius:4px; margin-right:8px;">
                                    <div style="width:<?= $rate ?>%; background:<?= $color ?>; height:100%; border-radius:4px;"></div>
                                </div>
                                <strong><?= $rate ?>%</strong>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; padding:20px;">No exam data available yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div id="resetModal" class="modal"><div class="modal-content">
    <h3>Reset Password: <span id="reset_name"></span></h3>
    <form method="post"><input type="hidden" name="form" value="reset_password"><input type="hidden" name="user_id" id="reset_id">
        <div class="group"><label>New Password</label><input type="password" name="new_password" required style="width:100%; margin-bottom:15px;"></div>
        <button type="submit" class="btn-main">Save</button><button type="button" onclick="closeModals()" class="btn-main" style="background:#ccc">Cancel</button>
    </form>
</div></div>

<div id="editModal" class="modal"><div class="modal-content">
    <h3>Edit Exam</h3>
    <form method="post"><input type="hidden" name="form" value="edit_exam"><input type="hidden" name="exam_id" id="edit_exam_id">
        <div class="group"><label>Title</label><input name="title" id="edit_title" required style="width:100%; margin-bottom:10px;"></div>
        <div class="group"><label>Category</label>
            <select name="description" id="edit_description" style="width:100%; margin-bottom:10px;">
                <option value="Prelim Exam">Prelim Exam</option>
                <option value="Midterm Exam">Midterm Exam</option>
                <option value="Final Exam">Final Exam</option>
                <option value="Quiz">Quiz</option>
                <option value="Long Quiz">Long Quiz</option>
                <option value="Recitation">Recitation</option>
            </select>
        </div>
        <div class="group"><label>Teacher</label>
            <select name="teacher_id" id="edit_teacher_id" style="width:100%; margin-bottom:15px;">
                <?php foreach($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['username']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-main">Update</button><button type="button" onclick="closeModals()" class="btn-main" style="background:#ccc">Cancel</button>
    </form>
</div></div>

<script>
function toggleSubMenu(id, tabId) { document.getElementById(id).classList.toggle('show'); showTab(tabId); }
function showUserForm(formId, filterType) {
    document.querySelectorAll('.form-section').forEach(f => f.classList.remove('active'));
    document.getElementById(formId).classList.add('active');
    const rows = document.querySelectorAll('#userTable tr');
    rows.forEach(row => {
        const role = row.getAttribute('data-role');
        if (filterType === 'staff') {
            row.style.display = (role === 'teacher' || role === 'admin') ? '' : 'none';
            document.getElementById('user-title').innerText = "Instructor & Admin Management";
        } else {
            row.style.display = (role === 'student') ? '' : 'none';
            document.getElementById('user-title').innerText = "Student Management";
        }
    });
}
function showTab(id) {
    document.querySelectorAll('.admin-tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    document.getElementById('btn-' + id).classList.add('active');
}
function openResetModal(id, name) { document.getElementById('reset_id').value = id; document.getElementById('reset_name').innerText = name; document.getElementById('resetModal').style.display = 'block'; }
function openEditModal(id, title, desc, tId) { document.getElementById('edit_exam_id').value = id; document.getElementById('edit_title').value = title; document.getElementById('edit_description').value = desc; document.getElementById('edit_teacher_id').value = tId; document.getElementById('editModal').style.display = 'block'; }
function closeModals() { document.querySelectorAll('.modal').forEach(m => m.style.display='none'); }
function confirmDelete(id, type) { if(confirm("Delete " + type + "?")) { const f = document.createElement('form'); f.method='POST'; f.innerHTML=`<input type="hidden" name="form" value="delete_${type}"><input type="hidden" name="id" value="${id}"><input type="hidden" name="current_tab" value="${document.querySelector('.admin-tab-content.active').id}">`; document.body.appendChild(f); f.submit(); } }
window.onclick = e => { if(e.target.className==='modal') closeModals(); }

window.addEventListener('DOMContentLoaded', () => { 
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab') || 'user-tab';
    showTab(tab); 
});
</script>
</body>
</html>