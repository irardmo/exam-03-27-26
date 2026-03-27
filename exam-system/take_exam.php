<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

require_login();
require_role('student');

$student_id = $_SESSION['user']['id'];
$exam_id = (int)($_GET['exam_id'] ?? 0);

// --- SET YOUR TIME LIMIT HERE (in minutes) ---
$duration_minutes = 60; 

if ($exam_id <= 0) {
    die("Invalid Exam ID.");
}

// 1. 🚫 Prevent re-entry (Allows only 1 Retake if Failed)
$stmtCount = $conn->prepare("SELECT id, raw_score, max_score FROM attempts WHERE exam_id = ? AND student_id = ? AND submitted_at IS NOT NULL ORDER BY submitted_at DESC");
$stmtCount->bind_param("ii", $exam_id, $student_id);
$stmtCount->execute();
$resCheck = $stmtCount->get_result();
$attempts_count = $resCheck->num_rows;

if ($attempts_count > 0) {
    $last_attempt = $resCheck->fetch_assoc();
    $score = $last_attempt['raw_score'] ?? 0;
    $max = $last_attempt['max_score'] ?? 0;
    $percentage = ($max > 0) ? ($score / $max) * 100 : 0;

    if ($percentage >= 75) {
        echo "<script>alert('Exam already completed with a passing grade.'); window.location.href='student_dashboard.php';</script>";
        exit;
    }

    if ($attempts_count >= 2) {
        echo "<script>alert('You have already used your one allowed retake for this exam.'); window.location.href='student_dashboard.php';</script>";
        exit;
    }
}

// 2. ✅ Fetch Exam details
$stmt = $conn->prepare("SELECT * FROM exams WHERE id = ?");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();

if (!$exam || $exam['is_active'] == 0) {
    echo "<script>alert('Exam unavailable.'); window.location.href='student_dashboard.php';</script>";
    exit;
}

// 3. 🕒 Attempt & Unique Question Logic (Filtered by Period)
$stmtAtt = $conn->prepare("SELECT id, selected_question_ids FROM attempts WHERE exam_id = ? AND student_id = ? AND submitted_at IS NULL LIMIT 1");
$stmtAtt->bind_param("ii", $exam_id, $student_id);
$stmtAtt->execute();
$existingAttempt = $stmtAtt->get_result()->fetch_assoc();

if ($existingAttempt) {
    $new_attempt_id = $existingAttempt['id'];
    $question_ids = json_decode($existingAttempt['selected_question_ids'], true);
} else {
    /* Logic: We pull from 'questions' where exam_id matches.
       Professional Tip: If you have a 'period' column in your questions table, 
       add "AND period = '{$exam['description']}'" to the WHERE clause.
    */
    // Group by question_text to ensure unique questions even if they are duplicated in the database
    $qstmt = $conn->prepare("SELECT MIN(id) as id FROM questions WHERE exam_id = ? GROUP BY question_text ORDER BY RAND() LIMIT 50");
    $qstmt->bind_param("i", $exam_id);
    $qstmt->execute();
    $q_res = $qstmt->get_result();
    
    $question_ids = [];
    while($row = $q_res->fetch_assoc()) {
        $question_ids[] = (int)$row['id'];
    }
    
    if (empty($question_ids)) {
        echo "<script>alert('No questions found for this specific period.'); window.location.href='student_dashboard.php';</script>";
        exit;
    }

    $selected_json = json_encode(array_values($question_ids));
    $stmtIn = $conn->prepare("INSERT INTO attempts (exam_id, student_id, selected_question_ids, started_at) VALUES (?, ?, ?, NOW())");
    $stmtIn->bind_param("iis", $exam_id, $student_id, $selected_json);
    $stmtIn->execute();
    $new_attempt_id = $stmtIn->insert_id;
}

// 4. ✅ Fetch Question Details (Sorted as saved)
$ids_placeholders = implode(',', array_fill(0, count($question_ids), '?'));
$qDataQuery = "SELECT * FROM questions WHERE id IN ($ids_placeholders)";
$qDataStmt = $conn->prepare($qDataQuery);
$qDataStmt->bind_param(str_repeat('i', count($question_ids)), ...$question_ids);
$qDataStmt->execute();
$fetched_questions = $qDataStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Final UI protection: Map questions by ID to maintain the random order correctly
$questions_by_id = [];
foreach($fetched_questions as $fq) {
    $questions_by_id[$fq['id']] = $fq;
}

include 'header.php'; 
?>

<style>
    #timer-header {
        position: sticky; top: 0; z-index: 1000;
        background: #2c3e50; color: #fff;
        padding: 15px; text-align: center;
        font-size: 1.6rem; font-weight: bold;
        border-bottom: 5px solid #e74c3c;
    }
    .time-critical { background: #e74c3c !important; color: white !important; }
    .q-card { background: #fff; padding: 25px; margin-bottom: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .option-label { display: block; padding: 10px; margin: 5px 0; border: 1px solid #f1f5f9; border-radius: 6px; cursor: pointer; transition: background 0.2s; }
    .option-label:hover { background: #f8fafc; }
</style>

<div id="timer-header">
    TIME REMAINING: <span id="time-display">--:--</span>
</div>

<div class="wrap" style="padding: 20px; max-width: 900px; margin: auto;">
    <div class="card">
        <h1 style="color: #1a1c2e;"><?= htmlspecialchars($exam['title']); ?></h1>
        <?php if ($attempts_count == 1): ?>
            <div style="background:#fff3cd; color:#856404; padding:15px; border-radius:8px; margin-bottom:20px; border-left: 5px solid #ffc107;">
                <strong>Final Retake:</strong> You are on your last attempt for this subject.
            </div>
        <?php endif; ?>
        <hr style="border: 0; border-top: 1px solid #eee; margin-bottom: 30px;">

        <form id="autoSubmitForm" action="submit_exam.php" method="POST">
            <input type="hidden" name="exam_id" value="<?= $exam_id; ?>">
            <input type="hidden" name="attempt_id" value="<?= $new_attempt_id; ?>"> 

            <?php 
            $count = 1;
            foreach ($question_ids as $qid): 
                if (!isset($questions_by_id[$qid])) continue;
                $q = $questions_by_id[$qid];
            ?>
                <div class="q-card">
                    <p style="font-size: 1.1rem; color: #2d3748;"><strong><?= $count++; ?>.</strong> <?= htmlspecialchars($q['question_text']); ?></p> 
                    <div style="margin-top: 15px;">
                        <label class="option-label"><input type="radio" name="answers[<?= $q['id']; ?>]" value="A" required> <strong>A.</strong> <?= htmlspecialchars($q['option_a']); ?></label>
                        <label class="option-label"><input type="radio" name="answers[<?= $q['id']; ?>]" value="B"> <strong>B.</strong> <?= htmlspecialchars($q['option_b']); ?></label>
                        <label class="option-label"><input type="radio" name="answers[<?= $q['id']; ?>]" value="C"> <strong>C.</strong> <?= htmlspecialchars($q['option_c']); ?></label>
                        <label class="option-label"><input type="radio" name="answers[<?= $q['id']; ?>]" value="D"> <strong>D.</strong> <?= htmlspecialchars($q['option_d']); ?></label>
                    </div>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="btn" style="width:100%; padding:20px; background:#4062ff; color:white; font-size: 1.2rem; border-radius: 10px; border:none; cursor:pointer;" onclick="return confirmSubmission()">SUBMIT EXAM</button>
        </form>
    </div>
</div>

<script>
// Timer Logic
const durationInMs = <?= (int)$duration_minutes; ?> * 60 * 1000;
const penaltyInMs = 10 * 60 * 1000; 
const storageKey = "exam_timer_<?= (int)$exam_id; ?>_<?= (int)$student_id; ?>";
const penaltyLockKey = "penalty_applied_lock";

let start = localStorage.getItem(storageKey);

if (!start || start === "NaN") {
    start = new Date().getTime();
    localStorage.setItem(storageKey, start);
} else {
    const penaltyLocked = sessionStorage.getItem(penaltyLockKey);
    if (performance.navigation.type === 1 && !penaltyLocked) {
        start = parseInt(start) - penaltyInMs;
        localStorage.setItem(storageKey, start);
        alert("⚠️ REFRESH PENALTY: 10 minutes deducted.");
    }
    sessionStorage.removeItem(penaltyLockKey);
}

// Tab Switching / Visibility Penalty
document.addEventListener("visibilitychange", function() {
    if (document.visibilityState === 'hidden') {
        sessionStorage.setItem(penaltyLockKey, "true");
        let currentStart = parseInt(localStorage.getItem(storageKey));
        localStorage.setItem(storageKey, currentStart - penaltyInMs);
    } else if (document.visibilityState === 'visible') {
        alert("⚠️ VIOLATION: You left the exam screen! 10 minutes deducted.");
        location.reload(); 
    }
});

const countdown = setInterval(function() {
    const now = new Date().getTime();
    const currentStart = parseInt(localStorage.getItem(storageKey));
    const target = currentStart + durationInMs;
    const remaining = target - now;
    const timeToDisplay = Math.max(0, remaining);
    
    const m = Math.floor(timeToDisplay / (1000 * 60));
    const s = Math.floor((timeToDisplay % (1000 * 60)) / 1000);

    const clockDisplay = document.getElementById("time-display");
    if (clockDisplay) {
        clockDisplay.innerHTML = (m < 10 ? "0"+m : m) + ":" + (s < 10 ? "0"+s : s);
        if (timeToDisplay < 300000) { 
            document.getElementById("timer-header").classList.add("time-critical"); 
        }
    }

    if (remaining <= 0) {
        clearInterval(countdown);
        localStorage.removeItem(storageKey);
        window.onbeforeunload = null; 
        alert("Time is up! Auto-submitting...");
        document.getElementById("autoSubmitForm").submit();
    }
}, 1000);

function confirmSubmission() {
    if(confirm("Are you sure you want to submit your answers?")) {
        localStorage.removeItem(storageKey);
        window.onbeforeunload = null;
        return true;
    }
    return false;
}

window.onbeforeunload = function() { return "Warning: Progress may be lost if you leave this page."; };
</script>

<?php include 'footer.php'; ?>