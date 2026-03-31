<?php 
session_start(); 
require_once 'db.php'; 
require_once 'helpers.php'; 
 
/** 
 * 1. SECURITY CHECK 
 */ 
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') { 
    header('Location: login.php'); 
    exit; 
} 
 
$teacher_id = (int)$_SESSION['user']['id']; 
$message = $_SESSION['teacher_msg'] ?? ''; 
$msg_type = $_SESSION['teacher_msg_type'] ?? 'info'; 
unset($_SESSION['teacher_msg'], $_SESSION['teacher_msg_type']); 
 
// --- PAGINATION & FILTER LOGIC --- 
$limit = 10; 
$upage = isset($_GET['upage']) ? max(1, (int)$_GET['upage']) : 1; 
$offset = ($upage - 1) * $limit; 
 
$f_exam = $_GET['f_exam'] ?? ''; 
$f_course = $_GET['f_course'] ?? ''; 
$f_section = $_GET['f_section'] ?? ''; 
$f_period = $_GET['f_period'] ?? '';
$f_search = $_GET['f_search'] ?? ''; 
 
$where_clauses = ["e.created_by = $teacher_id", "a.submitted_at IS NOT NULL"]; 
if($f_exam) $where_clauses[] = "e.id = " . (int)$f_exam; 
if($f_course) $where_clauses[] = "s.course = '" . $conn->real_escape_string($f_course) . "'"; 
if($f_section) $where_clauses[] = "s.year_section = '" . $conn->real_escape_string($f_section) . "'"; 
if($f_period) $where_clauses[] = "e.description = '" . $conn->real_escape_string($f_period) . "'";
 
if($f_search) { 
    $safe_s = $conn->real_escape_string($f_search); 
    $where_clauses[] = "(s.first_name LIKE '%$safe_s%' OR s.last_name LIKE '%$safe_s%')"; 
} 
$where_sql = implode(" AND ", $where_clauses); 
 
// --- COUNT TOTAL ROWS FOR PAGINATION --- 
$count_query = "SELECT COUNT(*) as total FROM attempts a JOIN exams e ON a.exam_id = e.id JOIN students s ON a.student_id = s.user_id WHERE $where_sql"; 
$count_res = $conn->query($count_query); 
$total_rows = $count_res->fetch_assoc()['total']; 
$total_pages = ceil($total_rows / $limit); 
 
/** 
 * 2. ACTION HANDLERS (POST) 
 */ 
if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
     
    // A. Manual Question Entry 
    if (isset($_POST['add_manual_question'])) { 
        $exam_id = (int)$_POST['exam_id']; 
        $stmt = $conn->prepare("INSERT INTO questions (exam_id, question_text, option_a, option_b, option_c, option_d, correct_answer, created_by) VALUES (?,?,?,?,?,?,?,?)"); 
        $ans = strtoupper(trim($_POST['correct_answer'])); 
        $stmt->bind_param('issssssi', $exam_id, $_POST['question'], $_POST['option_a'], $_POST['option_b'], $_POST['option_c'], $_POST['option_d'], $ans, $teacher_id); 
         
        if ($stmt->execute()) { 
            $_SESSION['teacher_msg'] = "✅ Question added successfully!"; 
            $_SESSION['teacher_msg_type'] = "success"; 
        } 
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=add-question"); 
        exit; 
    } 
 
    // B. Bulk CSV Upload 
    if (isset($_FILES['csv_file']) && !empty($_FILES['csv_file']['name'])) { 
        $exam_id = (int)$_POST['exam_id']; 
        $handle = fopen($_FILES['csv_file']['tmp_name'], "r"); 
        fgetcsv($handle); // Skip header row 
         
        $stmt = $conn->prepare("INSERT INTO questions (exam_id, question_text, option_a, option_b, option_c, option_d, correct_answer, created_by) VALUES (?,?,?,?,?,?,?,?)"); 
        $count = 0; 
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) { 
            if (count($row) < 6) continue; 
            $ans = strtoupper(trim($row[5])); 
            $stmt->bind_param('issssssi', $exam_id, $row[0], $row[1], $row[2], $row[3], $row[4], $ans, $teacher_id); 
            if ($stmt->execute()) $count++; 
        } 
        fclose($handle); 
        $_SESSION['teacher_msg'] = "✅ Imported $count questions successfully!"; 
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=upload-csv"); 
        exit; 
    } 
 
    // C. Update Password 
    if (isset($_POST['update_password'])) { 
        if ($_POST['new_password'] === $_POST['confirm_password']) { 
            $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT); 
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?"); 
            $stmt->bind_param('si', $hash, $teacher_id); 
            $stmt->execute(); 
            $_SESSION['teacher_msg'] = "✅ Password updated successfully!"; 
        } else { 
            $_SESSION['teacher_msg'] = "❌ Passwords do not match!"; 
            $_SESSION['teacher_msg_type'] = "danger"; 
        } 
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=settings"); 
        exit; 
    } 
 
    // D. Upload Anything (Multiple Files) 
    if (isset($_FILES['activity_files']) && !empty($_FILES['activity_files']['name'][0])) { 
        $exam_id = (int)$_POST['exam_id']; 
        $upload_dir = 'uploads/'; 
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true); 
 
        $count = 0; 
        $total_files = count($_FILES['activity_files']['name']); 
 
        for ($i = 0; $i < $total_files; $i++) { 
            if ($_FILES['activity_files']['error'][$i] === UPLOAD_ERR_OK) { 
                $original_name = $_FILES['activity_files']['name'][$i]; 
                $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION)); 
                $new_name = "res_" . time() . "_" . rand(1000, 9999) . "_" . $i . "." . $file_ext; 
                $target_path = $upload_dir . $new_name; 
 
                if (move_uploaded_file($_FILES['activity_files']['tmp_name'][$i], $target_path)) { 
                    $stmt = $conn->prepare("INSERT INTO exam_resources (exam_id, file_path) VALUES (?, ?)"); 
                    $stmt->bind_param('is', $exam_id, $target_path); 
                    $stmt->execute(); 
                    $count++; 
                } 
            } 
        } 
 
        if ($count > 0) { 
            $_SESSION['teacher_msg'] = "✅ $count file(s) uploaded successfully!"; 
            $_SESSION['teacher_msg_type'] = "success"; 
        } 
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=upload-image"); 
        exit; 
    } 
} 
 
/** 
 * 3. ACTION HANDLERS (GET) 
 */ 
if (isset($_GET['toggle_exam'])) { 
    $id = (int)$_GET['toggle_exam']; 
    $conn->query("UPDATE exams SET is_active = 1 - is_active WHERE id = $id AND created_by = $teacher_id"); 
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=my-subjects"); 
    exit; 
} 
 
// Delete Resource Handler 
if (isset($_GET['delete_img'])) { 
    $img_id = (int)$_GET['delete_img']; 
    $res = $conn->query("SELECT file_path FROM exam_resources WHERE id = $img_id"); 
    if ($row = $res->fetch_assoc()) { 
        if (file_exists($row['file_path'])) unlink($row['file_path']); 
        $conn->query("DELETE FROM exam_resources WHERE id = $img_id"); 
        $_SESSION['teacher_msg'] = "🗑️ File deleted successfully!"; 
        $_SESSION['teacher_msg_type'] = "success"; 
    } 
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=upload-image"); 
    exit; 
} 
?> 
 
<!DOCTYPE html> 
<html lang="en"> 
<head> 
    <meta charset="UTF-8"> 
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Teacher Dashboard | Online Exam System</title> 
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet"> 
     
    <style> 
        /* --- CSS SEPARATED FROM HTML --- */ 
        :root { 
            --td-primary: #3b82f6; 
            --td-primary-dark: #2563eb; 
            --td-sidebar: 260px; 
            --td-dark: #0f172a; 
            --td-bg: #f8fafc; 
            --td-border: #e2e8f0; 
            --td-text: #334155; 
            --td-gray: #64748b; 
        } 
 
        * { box-sizing: border-box; } 
        body {  
            margin: 0;  
            font-family: 'Poppins', sans-serif;  
            background: var(--td-bg);  
            color: var(--td-text);  
            display: flex;  
            min-height: 100vh;  
        } 
 
        /* Sidebar Navigation */ 
        .teacher-dashboard-sidebar {  
            width: var(--td-sidebar);  
            background: var(--td-dark);  
            color: white;  
            position: fixed;  
            height: 100%;  
            display: flex;  
            flex-direction: column;  
            transition: 0.3s;  
            z-index: 1000;  
        } 
 
        .teacher-dashboard-sidebar-header {  
            padding: 25px;  
            font-weight: 700;  
            font-size: 1.2rem;  
            color: var(--td-primary);  
            border-bottom: 1px solid #1e293b;  
        } 
 
        .teacher-dashboard-nav-item {  
            padding: 15px 25px;  
            cursor: pointer;  
            display: flex;  
            align-items: center;  
            color: #94a3b8;  
            text-decoration: none;  
            border: none;  
            background: none;  
            width: 100%;  
            text-align: left;  
            font-size: 0.95rem;  
            transition: 0.2s;  
            gap: 12px; 
        } 
 
        .teacher-dashboard-nav-item:hover,  
        .teacher-dashboard-nav-item.active {  
            background: #1e293b;  
            color: white;  
        } 
 
        .teacher-dashboard-nav-item.active {  
            border-right: 4px solid var(--td-primary);  
        } 
 
        .teacher-dashboard-sub-nav {  
            display: none;  
            background: #020617;  
        } 
 
        .teacher-dashboard-sub-nav.show { display: block; } 
 
        /* Drag and Drop Zone */ 
        .upload-drop-zone { 
            border: 2px dashed #cbd5e1; 
            border-radius: 12px; 
            padding: 40px; 
            text-align: center; 
            background: #f8fafc; 
            transition: 0.3s; 
            cursor: pointer; 
            margin-bottom: 20px; 
        } 
 
        .upload-drop-zone.dragover { 
            background: #eef2ff; 
            border-color: var(--td-primary); 
        } 
 
        .upload-drop-zone p { 
            margin: 0; 
            color: var(--td-gray); 
            font-size: 0.95rem; 
        } 
 
        .upload-drop-zone .icon { 
            font-size: 2rem; 
            color: var(--td-primary); 
            margin-bottom: 10px; 
            display: block; 
        } 
 
        .teacher-dashboard-sub-item {  
            padding: 12px 20px 12px 55px;  
            font-size: 0.85rem;  
            color: #64748b;  
            cursor: pointer;  
            display: block;  
            border: none;  
            background: none;  
            width: 100%;  
            text-align: left;  
            transition: 0.2s; 
        } 
 
        .teacher-dashboard-sub-item:hover,  
        .teacher-dashboard-sub-item.active { color: white; } 
 
        /* Main Content */ 
        .teacher-dashboard-main {  
            margin-left: var(--td-sidebar);  
            flex: 1;  
            padding: 40px;  
            transition: 0.3s;  
        } 
 
        .teacher-dashboard-card {  
            background: white;  
            padding: 25px;  
            border-radius: 12px;  
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);  
            border: 1px solid var(--td-border);  
            margin-bottom: 20px;  
        } 
 
        /* Filter Box */ 
        .teacher-dashboard-filter-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 15px; 
            background: #f1f5f9; 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 20px; 
            align-items: flex-end; 
        } 
 
        .teacher-dashboard-input-wrapper label { 
            display: block; 
            font-size: 11px; 
            font-weight: 700; 
            text-transform: uppercase; 
            color: var(--td-gray); 
            margin-bottom: 5px; 
        } 
 
        .teacher-dashboard-input-wrapper input, 
        .teacher-dashboard-input-wrapper select { 
            width: 100%; 
            padding: 10px; 
            border-radius: 6px; 
            border: 1px solid #cbd5e1; 
        } 
 
        /* Tables */ 
        .teacher-dashboard-table-container { overflow-x: auto; } 
         
        table { width: 100%; border-collapse: collapse; } 
         
        th {  
            text-align: left;  
            padding: 12px;  
            background: #f8fafc;  
            color: var(--td-gray);  
            font-size: 0.8rem;  
            text-transform: uppercase;  
            border-bottom: 2px solid var(--td-border); 
        } 
 
        td {  
            padding: 15px 12px;  
            border-bottom: 1px solid #f1f5f9;  
            font-size: 0.9rem;  
        } 
 
        /* UI Components */ 
        .teacher-dashboard-btn { 
            padding: 10px 20px; 
            border-radius: 6px; 
            border: none; 
            cursor: pointer; 
            font-weight: 500; 
            background: var(--td-primary); 
            color: white; 
            transition: 0.2s; 
            text-decoration: none; 
            display: inline-block; 
            text-align: center; 
        } 
 
        .teacher-dashboard-btn:hover { opacity: 0.9; } 
         
        .teacher-dashboard-alert { 
            padding: 15px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            background: #ecfdf5; 
            color: #065f46; 
            border: 1px solid #a7f3d0; 
        } 
 
        .badge { background: #eef2ff; color: #4361ee; padding: 4px 8px; border-radius: 4px; font-weight: 700; } 
         
        /* Pagination Styling */ 
        .pagination { margin-top: 20px; display: flex; gap: 8px; justify-content: center; } 
        .page-link { padding: 8px 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; text-decoration: none; color: #4062ff; font-weight: 600; } 
        .page-link.active { background: #4062ff; color: #fff; border-color: #4062ff; } 
 
        /* Tab Visibility */ 
        .tab-content { display: none; } 
        .tab-content.active { display: block; animation: tdFadeIn 0.3s ease; } 
 
        @keyframes tdFadeIn { from { opacity: 0; } to { opacity: 1; } } 
 
        /* --- MOBILE RESPONSIVENESS --- */ 
        @media (max-width: 768px) { 
            .teacher-dashboard-sidebar { transform: translateX(-100%); width: 100%; } 
            .teacher-dashboard-sidebar.mobile-active { transform: translateX(0); } 
            .teacher-dashboard-main { margin-left: 0; padding: 20px; } 
            .teacher-dashboard-filter-grid { grid-template-columns: 1fr; } 
        } 
    </style> 
</head> 
<body class="teacher-dashboard-body"> 
 
<aside class="teacher-dashboard-sidebar" id="tdSidebar"> 
    <div class="teacher-dashboard-sidebar-header">TEACHER DASHBOAR</div> 
    <div style="flex: 1; padding: 20px 0;"> 
        <button class="teacher-dashboard-nav-item tab-trigger" data-tab-target="my-subjects">📚 My Subjects</button> 
        <button class="teacher-dashboard-nav-item tab-trigger" data-tab-target="student-results">📊 Exam Results</button> 
         
        <button class="teacher-dashboard-nav-item" id="tdManageToggle">⚙️ Question Bank ▼</button> 
        <div class="teacher-dashboard-sub-nav" id="tdManageMenu"> 
            <button class="teacher-dashboard-sub-item tab-trigger" data-tab-target="add-question">Manual Entry</button> 
            <button class="teacher-dashboard-sub-item tab-trigger" data-tab-target="upload-csv">CSV Bulk Upload</button> 
            <button class="teacher-dashboard-sub-item tab-trigger" data-tab-target="upload-image">Upload Anything</button> 
        </div> 
         
        <button class="teacher-dashboard-nav-item tab-trigger" data-tab-target="settings">👤 Account Settings</button> 
    </div> 
    <div style="padding: 20px;"> 
        <a href="logout.php" class="teacher-dashboard-nav-item" style="color: #ef4444;">Sign Out</a> 
    </div> 
</aside> 
 
<main class="teacher-dashboard-main"> 
    <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 30px;"> 
        <h2 id="tdPageTitle">Dashboard Overview</h2> 
        <button class="teacher-dashboard-btn" id="tdMobileNav" style="display:none;">Menu</button> 
    </div> 
 
    <?php if ($message): ?> 
        <div class="teacher-dashboard-alert"><?= $message ?></div> 
    <?php endif; ?> 
 
    <div id="my-subjects" class="tab-content active"> 
        <div class="teacher-dashboard-card"> 
            <div class="teacher-dashboard-table-container"> 
                <table> 
                    <thead> 
                        <tr> 
                            <th>Subject Name</th> 
                            <th>Category/Period</th> 
                            <th>Status</th> 
                            <th>Action</th> 
                        </tr> 
                    </thead> 
                    <tbody> 
                        <?php 
                        $exams = $conn->query("SELECT * FROM exams WHERE created_by = $teacher_id ORDER BY id DESC"); 
                        while($e = $exams->fetch_assoc()): ?> 
                        <tr> 
                            <td><strong><?= htmlspecialchars($e['title']) ?></strong></td> 
                            <td><?= htmlspecialchars($e['description']) ?></td> 
                            <td><?= $e['is_active'] ? '<span style="color:green">Active</span>' : '<span style="color:gray">Hidden</span>' ?></td> 
                            <td><a href="?toggle_exam=<?= $e['id'] ?>" class="teacher-dashboard-btn" style="background:#64748b; font-size:12px; padding:6px 12px;">Toggle</a></td> 
                        </tr> 
                        <?php endwhile; ?> 
                    </tbody> 
                </table> 
            </div> 
        </div> 
    </div> 
 
    <div id="student-results" class="tab-content"> 
        <div class="teacher-dashboard-card"> 
            <form method="GET" class="teacher-dashboard-filter-grid"> 
                <input type="hidden" name="tab" value="student-results"> 
                 
                <div class="teacher-dashboard-input-wrapper"> 
                    <label>Subject</label> 
                    <select name="f_exam"> 
                        <option value="">All Subjects</option> 
                        <?php  
                        $sub_opt = $conn->query("SELECT id, title FROM exams WHERE created_by = $teacher_id"); 
                        while($so = $sub_opt->fetch_assoc()): ?> 
                            <option value="<?= $so['id'] ?>" <?= ($f_exam == $so['id']) ? 'selected' : '' ?>><?= htmlspecialchars($so['title']) ?></option> 
                        <?php endwhile; ?> 
                    </select> 
                </div> 
 
                <div class="teacher-dashboard-input-wrapper">
                    <label>Period</label>
                    <select name="f_period">
                        <option value="">All Periods</option>
                        <option value="Prelim Exam" <?= ($f_period == 'Prelim Exam') ? 'selected' : '' ?>>Prelim Exam</option>
                        <option value="Midterm Exam" <?= ($f_period == 'Midterm Exam') ? 'selected' : '' ?>>Midterm Exam</option>
                        <option value="Final Exam" <?= ($f_period == 'Final Exam') ? 'selected' : '' ?>>Final Exam</option>
                    </select>
                </div>

                <div class="teacher-dashboard-input-wrapper"> 
                    <label>Course</label> 
                    <select name="f_course"> 
                        <option value="">All Courses</option> 
                        <?php  
                        $co_opt = $conn->query("SELECT DISTINCT course FROM students WHERE course != '' ORDER BY course ASC"); 
                        while($co = $co_opt->fetch_assoc()): ?> 
                            <option value="<?= htmlspecialchars($co['course']) ?>" <?= ($f_course == $co['course']) ? 'selected' : '' ?>><?= htmlspecialchars($co['course']) ?></option> 
                        <?php endwhile; ?> 
                    </select> 
                </div> 
 
                <div class="teacher-dashboard-input-wrapper"> 
                    <label>Search Student</label> 
                    <input type="text" name="f_search" placeholder="Enter name..." value="<?= htmlspecialchars($f_search) ?>"> 
                </div> 
 
                <div style="display:flex; gap:10px;"> 
                    <button type="submit" class="teacher-dashboard-btn">Filter</button> 
                    <a href="export_results.php?<?= http_build_query($_GET) ?>" class="teacher-dashboard-btn" style="background:#27ae60;">📥 Export</a> 
                </div> 
            </form> 
 
            <div class="teacher-dashboard-table-container"> 
                <table> 
                    <thead> 
                        <tr> 
                            <th>Student</th> 
                            <th>Course/Section</th> 
                            <th>Subject</th> 
                            <th>Score</th> 
                            <th>Rating</th> 
                        </tr> 
                    </thead> 
                    <tbody> 
                        <?php 
                        $results = $conn->query("SELECT CONCAT(s.first_name,' ',s.last_name) as name, s.course, s.year_section, e.title, a.raw_score, a.max_score  
                            FROM attempts a JOIN exams e ON a.exam_id = e.id JOIN students s ON a.student_id = s.user_id  
                            WHERE $where_sql ORDER BY a.submitted_at DESC LIMIT $limit OFFSET $offset"); 
                         
                        if($results && $results->num_rows > 0): 
                            while($r = $results->fetch_assoc()): ?> 
                            <tr> 
                                <td><strong><?= htmlspecialchars($r['name']) ?></strong></td> 
                                <td><?= htmlspecialchars($r['course']) ?> - <?= htmlspecialchars($r['year_section']) ?></td> 
                                <td><?= htmlspecialchars($r['title']) ?></td> 
                                <td><?= $r['raw_score'] ?> / <?= $r['max_score'] ?></td> 
                                <td><span class="badge"><?= number_format(transmute($r['raw_score']), 2) ?></span></td> 
                            </tr> 
                        <?php endwhile; else: ?> 
                            <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--td-gray);">No student records found.</td></tr> 
                        <?php endif; ?> 
                    </tbody> 
                </table> 
            </div> 
 
            <?php if ($total_pages > 1): ?> 
            <div class="pagination"> 
                <?php for ($i = 1; $i <= $total_pages; $i++): ?> 
                    <a href="?tab=student-results&upage=<?= $i ?>&f_search=<?= urlencode($f_search) ?>&f_exam=<?= $f_exam ?>"  
                       class="page-link <?= ($upage == $i) ? 'active' : '' ?>"> 
                        <?= $i ?> 
                    </a> 
                <?php endfor; ?> 
            </div> 
            <?php endif; ?> 
 
        </div> 
    </div> 
 
    <div id="add-question" class="tab-content"> 
        <div class="teacher-dashboard-card" style="max-width: 700px;"> 
            <h3>New Question</h3> 
            <form method="POST"> 
                <input type="hidden" name="add_manual_question" value="1"> 
                <div class="teacher-dashboard-input-wrapper" style="margin-bottom:15px;"> 
                    <label>Subject Period</label> 
                    <select name="exam_id" required> 
                        <?php  
                        $sub = $conn->query("SELECT id, title FROM exams WHERE created_by = $teacher_id"); 
                        while($s = $sub->fetch_assoc()) echo "<option value='{$s['id']}'>".htmlspecialchars($s['title'])."</option>"; 
                        ?> 
                    </select> 
                </div> 
                <div class="teacher-dashboard-input-wrapper" style="margin-bottom:15px;"> 
                    <label>Question Text</label> 
                    <textarea name="question" rows="4" style="width:100%; padding:10px; border-radius:6px; border:1px solid #cbd5e1;" required></textarea> 
                </div> 
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;"> 
                    <input type="text" name="option_a" placeholder="Option A" required style="padding:10px; border-radius:6px; border:1px solid #cbd5e1;"> 
                    <input type="text" name="option_b" placeholder="Option B" required style="padding:10px; border-radius:6px; border:1px solid #cbd5e1;"> 
                    <input type="text" name="option_c" placeholder="Option C" required style="padding:10px; border-radius:6px; border:1px solid #cbd5e1;"> 
                    <input type="text" name="option_d" placeholder="Option D" required style="padding:10px; border-radius:6px; border:1px solid #cbd5e1;"> 
                </div> 
                <div class="teacher-dashboard-input-wrapper" style="margin-bottom:20px;"> 
                    <label>Correct Answer</label> 
                    <select name="correct_answer"> 
                        <option>A</option><option>B</option><option>C</option><option>D</option> 
                    </select> 
                </div> 
                <button type="submit" class="teacher-dashboard-btn" style="width:100%;">Add Question to Database</button> 
            </form> 
        </div> 
    </div> 
 
    <div id="upload-csv" class="tab-content"> 
        <div class="teacher-dashboard-card" style="max-width: 500px;"> 
            <h3>CSV Bulk Import</h3> 
            <p style="font-size:0.8rem; color:var(--td-gray); margin-bottom: 20px;">Columns: Question, A, B, C, D, Correct (A/B/C/D)</p> 
            <form method="POST" enctype="multipart/form-data"> 
                <div class="teacher-dashboard-input-wrapper" style="margin-bottom:15px;"> 
                    <label>Target Subject</label> 
                    <select name="exam_id"> 
                        <?php  
                        $sub = $conn->query("SELECT id, title FROM exams WHERE created_by = $teacher_id"); 
                        while($s = $sub->fetch_assoc()) echo "<option value='{$s['id']}'>".htmlspecialchars($s['title'])."</option>"; 
                        ?> 
                    </select> 
                </div> 
                <input type="file" name="csv_file" accept=".csv" required style="margin-bottom: 20px;"> 
                <button type="submit" class="teacher-dashboard-btn" style="width:100%;">Process File Upload</button> 
            </form> 
        </div> 
    </div> 
 
    <div id="upload-image" class="tab-content"> 
        <div class="teacher-dashboard-card"> 
            <h3>Upload Anything</h3> 
            <form id="uploadForm" method="POST" enctype="multipart/form-data" style="margin-bottom: 40px; padding: 20px; background: #f8fafc; border-radius: 8px;"> 
                <div class="teacher-dashboard-input-wrapper" style="margin-bottom:15px;"> 
                    <label>Associate with Subject</label> 
                    <select name="exam_id" required> 
                        <?php  
                        $sub = $conn->query("SELECT id, title FROM exams WHERE created_by = $teacher_id"); 
                        while($s = $sub->fetch_assoc()) { 
                            echo "<option value='{$s['id']}'>".htmlspecialchars($s['title'])."</option>"; 
                        } 
                        ?> 
                    </select> 
                </div> 
 
                <div class="upload-drop-zone" id="dropZone"> 
                    <span class="icon">📁</span> 
                    <p>Drag & Drop files or folders here, or <strong>click to browse</strong></p> 
                    <input type="file" name="activity_files[]" id="fileInput" multiple style="display: none;"> 
                </div> 
 
                <div id="fileList" style="margin-bottom: 15px; font-size: 0.85rem; color: var(--td-gray);"></div> 
 
                <button type="submit" class="teacher-dashboard-btn">Upload to Subject</button> 
            </form> 
            <hr> 
            <h3>Managed Resources</h3> 
            <div class="teacher-resource-page-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;"> 
                <?php 
                // Fetching resources for this teacher 
                $img_q = "SELECT r.id, r.file_path, e.title as exam_name FROM exam_resources r JOIN exams e ON r.exam_id = e.id WHERE e.created_by = $teacher_id"; 
                $resources = $conn->query($img_q); 
 
                if ($resources && $resources->num_rows > 0): 
                    while($res = $resources->fetch_assoc()):  
                        $path = htmlspecialchars($res['file_path']); 
                        $filename = basename($path); 
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); 
                        $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']); 
                ?> 
                    <div class="teacher-resource-page-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; flex-direction: column;"> 
                         
                        <div class="preview-box" style="height: 130px; border-bottom: 1px solid #edf2f7; overflow: hidden;"> 
                            <?php if ($is_image): ?> 
                                <img src="<?= $path ?>" style="width: 100%; height: 100%; object-fit: cover;"> 
                            <?php else: ?> 
                                <div style="width: 100%; height: 100%; background: #f8fafc; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #64748b;"> 
                                    .<?= strtoupper($ext) ?> 
                                </div> 
                            <?php endif; ?> 
                        </div> 
                         
                        <div style="padding: 15px;"> 
                            <small style="display:block; color: var(--td-primary); font-weight: 600; margin-bottom: 5px;"><?= htmlspecialchars($res['exam_name']) ?></small> 
                            <div style="font-size: 12px; color: #4a5568; word-break: break-all; margin-bottom: 15px; height: 35px; overflow: hidden;" title="<?= $filename ?>"> 
                                <?= $filename ?> 
                            </div> 
                             
                            <div style="display: flex; flex-direction: column; gap: 10px;"> 
                                <a href="<?= $path ?>" download="<?= $filename ?>" class="teacher-dashboard-btn" style="text-align: center; background: #2ecc71; text-decoration: none; padding: 8px; font-size: 13px;"> 
                                    Download File 
                                </a> 
                                 
                                <a href="?delete_img=<?= $res['id'] ?>" onclick="return confirm('Delete this file?')" style="text-align: center; color: #e53e3e; text-decoration: none; font-size: 11px; font-weight: 600; border: 1px solid #fed7d7; padding: 5px; border-radius: 4px; background: #fff5f5;"> 
                                    Delete 
                                </a> 
                            </div> 
                        </div> 
                    </div> 
                <?php endwhile; else: ?> 
                    <p style="grid-column: 1/-1; text-align: center; color: #a0aec0; padding: 40px;">No files found for your subjects.</p> 
                <?php endif; ?> 
            </div> 
        </div> 
    </div> 
 
    <div id="settings" class="tab-content"> 
        <div class="teacher-dashboard-card" style="max-width: 400px;"> 
            <h3>Security Settings</h3> 
            <form method="POST"> 
                <input type="hidden" name="update_password" value="1"> 
                <div class="teacher-dashboard-input-wrapper" style="margin-bottom:15px;"> 
                    <label>New Password</label> 
                    <input type="password" name="new_password" required minlength="6"> 
                </div> 
                <div class="teacher-dashboard-input-wrapper" style="margin-bottom:15px;"> 
                    <label>Confirm Password</label> 
                    <input type="password" name="confirm_password" required> 
                </div> 
                <button type="submit" class="teacher-dashboard-btn" style="background:#e67e22; width:100%;">Update My Password</button> 
            </form> 
        </div> 
    </div> 
</main> 
 
<script> 
document.addEventListener('DOMContentLoaded', () => { 
    const tdManageToggle = document.getElementById('tdManageToggle'); 
    const tdManageMenu = document.getElementById('tdManageMenu'); 
    const tdPageTitle = document.getElementById('tdPageTitle'); 
    const sidebar = document.getElementById('tdSidebar'); 
    const mobileBtn = document.getElementById('tdMobileNav'); 
 
    // Sidebar Visibility for Mobile 
    if (window.innerWidth <= 768) { 
        mobileBtn.style.display = 'block'; 
        mobileBtn.onclick = () => sidebar.classList.toggle('mobile-active'); 
    } 
 
    // Toggle Sub-menu 
    tdManageToggle.onclick = () => tdManageMenu.classList.toggle('show'); 
 
    // Tab Switching Logic 
    const tabTriggers = document.querySelectorAll('.tab-trigger'); 
    const tabContents = document.querySelectorAll('.tab-content'); 
 
    function switchTab(id) { 
        tabTriggers.forEach(t => t.classList.remove('active')); 
        tabContents.forEach(c => c.classList.remove('active')); 
 
        const targetBtn = document.querySelector(`[data-tab-target="${id}"]`); 
        const targetContent = document.getElementById(id); 
 
        if(targetContent) { 
            targetContent.classList.add('active'); 
            if(targetBtn) targetBtn.classList.add('active'); 
             
            // Clean title update 
            tdPageTitle.innerText = targetBtn.innerText.replace(/[^\w\s]/gi, '').trim(); 
            localStorage.setItem('active_teacher_tab', id); 
             
            // Keep menu open for sub-items 
            if(['add-question', 'upload-csv', 'upload-image'].includes(id)) { 
                tdManageMenu.classList.add('show'); 
            } 
             
            // Auto-close sidebar on mobile after selection 
            if(window.innerWidth <= 768) sidebar.classList.remove('mobile-active'); 
        } 
    } 
 
    tabTriggers.forEach(btn => { 
        btn.onclick = () => switchTab(btn.dataset.tabTarget); 
    }); 
 
    // Persistence 
    const urlParams = new URLSearchParams(window.location.search); 
    const currentTab = urlParams.get('tab') || localStorage.getItem('active_teacher_tab') || 'my-subjects'; 
    switchTab(currentTab); 
 
    // Drag and Drop Implementation 
    const dropZone = document.getElementById('dropZone'); 
    const fileInput = document.getElementById('fileInput'); 
    const fileList = document.getElementById('fileList'); 
    const uploadForm = document.getElementById('uploadForm'); 
    const MAX_UPLOAD_SIZE = 300 * 1024 * 1024; // 300MB 
 
    if (dropZone) { 
        dropZone.onclick = () => fileInput.click(); 
 
        dropZone.ondragover = (e) => { 
            e.preventDefault(); 
            dropZone.classList.add('dragover'); 
        }; 
 
        dropZone.ondragleave = () => { 
            dropZone.classList.remove('dragover'); 
        }; 
 
        dropZone.ondrop = async (e) => { 
            e.preventDefault(); 
            dropZone.classList.remove('dragover'); 
             
            const dt = new DataTransfer();
            const items = e.dataTransfer.items;
             
            if (items) {
                for (let i = 0; i < items.length; i++) {
                    const entry = items[i].webkitGetAsEntry();
                    if (entry) {
                        await traverseFileTree(entry, dt);
                    }
                }
                fileInput.files = dt.files;
            } else {
                fileInput.files = e.dataTransfer.files;
            }
            updateFileList(); 
        }; 
 
        async function traverseFileTree(entry, dt) {
            if (entry.isFile) {
                const file = await new Promise((resolve) => entry.file(resolve));
                dt.items.add(file);
            } else if (entry.isDirectory) {
                const dirReader = entry.createReader();
                let entries = [];
                let readEntries = async () => {
                    let result = await new Promise((resolve) => dirReader.readEntries(resolve));
                    if (result.length > 0) {
                        entries = entries.concat(result);
                        await readEntries();
                    }
                };
                await readEntries();
                for (let i = 0; i < entries.length; i++) {
                    await traverseFileTree(entries[i], dt);
                }
            }
        }
 
        fileInput.onchange = () => updateFileList(); 
 
        function formatBytes(bytes, decimals = 2) { 
            if (bytes === 0) return '0 Bytes'; 
            const k = 1024; 
            const dm = decimals < 0 ? 0 : decimals; 
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']; 
            const i = Math.floor(Math.log(bytes) / Math.log(k)); 
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i]; 
        } 
 
        function updateFileList() { 
            const files = fileInput.files; 
            let totalSize = 0; 
            for (let i = 0; i < files.length; i++) { 
                totalSize += files[i].size; 
            } 
 
            if (files.length > 0) { 
                let sizeColor = totalSize > MAX_UPLOAD_SIZE ? '#ef4444' : 'var(--td-gray)'; 
                fileList.innerHTML = `<strong>Selected:</strong> ${files.length} file(s) - <span style="color: ${sizeColor}">${formatBytes(totalSize)}</span>`; 
                if (totalSize > MAX_UPLOAD_SIZE) { 
                    fileList.innerHTML += `<br><small style="color: #ef4444;">⚠️ Total size exceeds the 300MB limit. Please remove some files.</small>`; 
                } 
            } else { 
                fileList.innerHTML = ''; 
            } 
        } 
 
        if (uploadForm) { 
            uploadForm.onsubmit = (e) => { 
                const files = fileInput.files; 
                let totalSize = 0; 
                for (let i = 0; i < files.length; i++) { 
                    totalSize += files[i].size; 
                } 
 
                if (totalSize > MAX_UPLOAD_SIZE) { 
                    e.preventDefault(); 
                    alert('❌ Upload failed: The total size of ' + formatBytes(totalSize) + ' exceeds the 300MB limit.'); 
                    return false; 
                } 
                if (files.length === 0) { 
                    e.preventDefault(); 
                    alert('❌ Please select at least one file to upload.'); 
                    return false; 
                } 
            }; 
        } 
    } 
}); 
</script> 
 
</body> 
</html>