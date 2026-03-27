<?php
/**
 * submit_exam.php
 * Final Resilient Version - Prevents logouts during network/session jitters
 */

// 1. Get attempt_id from POST immediately (the most reliable source)
$attempt_id = (int)($_POST['attempt_id'] ?? 0); 

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/similarity.php'; 

/**
 * RELIABILITY FIX: SOFT AUTHENTICATION
 * We manually verify the user instead of using require_role('student').
 * This prevents the student from being redirected to logout.php if the session blips.
 */
if (!isset($_SESSION['user'])) {
    // If session is lost, try to identify the student via the existing attempt record
    $stmtVerify = $conn->prepare("SELECT student_id FROM attempts WHERE id = ? AND submitted_at IS NULL");
    $stmtVerify->bind_param('i', $attempt_id);
    $stmtVerify->execute();
    $resVerify = $stmtVerify->get_result()->fetch_assoc();

    if ($resVerify) {
        $student_id = (int)$resVerify['student_id'];
    } else {
        // Only redirect if we truly cannot find who this is
        redirect('login.php?error=session_lost');
        exit();
    }
} else {
    // Session is healthy
    $student_id = (int)$_SESSION['user']['id'];
}

// Ensure the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $attempt_id <= 0) {
    redirect('student_dashboard.php');
    exit();
}

$answers = $_POST['answers'] ?? [];
$raw_score = 0;
$needs_manual = 0;

/*
 * 2) Verify Attempt and Retrieve Question IDs from DB
 */
$stmtA = $conn->prepare("SELECT exam_id, selected_question_ids FROM attempts WHERE id=? AND student_id=? AND submitted_at IS NULL");

if (!$stmtA) {
    die("Database error: " . $conn->error);
}

$stmtA->bind_param('ii', $attempt_id, $student_id);
$stmtA->execute();
$attempt_data = $stmtA->get_result()->fetch_assoc();
$stmtA->close();

if (!$attempt_data) {
    die("No active attempt found. It may have already been submitted.");
}

$exam_id = $attempt_data['exam_id'];
$question_ids_db = json_decode($attempt_data['selected_question_ids'], true) ?: []; 
$max_score = count($question_ids_db); 

if (empty($question_ids_db)) {
    die("Attempt data missing question set.");
}

// Only grade answers for questions that were actually assigned to this attempt
$question_ids_to_grade = array_values(array_intersect($question_ids_db, array_keys($answers)));

/*
 * 3) Fetch Question Details and Grade
 */
if (!empty($question_ids_to_grade)) {
    $in = implode(',', array_fill(0, count($question_ids_to_grade), '?'));
    $types = str_repeat('i', count($question_ids_to_grade));
    
    $sql = "SELECT id, type, correct_answer, answer_text FROM questions WHERE id IN ($in)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$question_ids_to_grade);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $grade_inserts = [];
    
    while ($q = $res->fetch_assoc()) {
        $qid = (int)$q['id'];
        $qtype = $q['type'] ?? 'mcq';
        $correct_option = $q['correct_answer'] ?? ''; 
        $correct_text = $q['answer_text'] ?? '';
        $student_answer = trim($answers[$qid] ?? '');

        $score = 0;
        $is_correct = 0;

        if ($qtype === 'mcq') {
            if ($student_answer !== '' && strcasecmp($student_answer, $correct_option) === 0) {
                $score = 1;
                $is_correct = 1;
            }
        } else { 
            // Fill or Essay Logic
            if ($student_answer !== '' && $correct_text !== '') {
                $sa = strtolower(trim(preg_replace('/\s+/', ' ', $student_answer)));
                $ca = strtolower(trim(preg_replace('/\s+/', ' ', $correct_text)));

                if ($sa === $ca) {
                    $score = 1; $is_correct = 1;
                } elseif (function_exists('is_synonym') && is_synonym($sa, $ca)) {
                    $score = 1; $is_correct = 1;
                } elseif (function_exists('string_similarity') && string_similarity($sa, $ca) >= 0.8) {
                    $score = 1; $is_correct = 1;
                } else {
                    $needs_manual = 1; 
                }
            } else {
                $needs_manual = 1; 
            }
        }

        $raw_score += $score;
        $grade_inserts[] = [$attempt_id, $qid, $student_answer, $is_correct];
    }
    $stmt->close();
    
    // Batch Insert Answers
    if (!empty($grade_inserts)) {
        $values = [];
        $params = [];
        $v_types = '';
        foreach ($grade_inserts as $idat) {
            $values[] = '(?, ?, ?, ?)';
            $v_types .= 'iisi';
            $params = array_merge($params, $idat);
        }
        $sqlAns = "INSERT INTO attempt_answers (attempt_id, question_id, selected_answer, is_correct) VALUES " . implode(', ', $values);
        $stmtAns = $conn->prepare($sqlAns);
        $stmtAns->bind_param($v_types, ...$params);
        $stmtAns->execute();
        $stmtAns->close();
    }
}

/*
 * 4) Final Update and Transmute
 */
$percentage = ($max_score > 0) ? ($raw_score / $max_score) * 100 : 0;
// Note: Ensure transmute() function exists in helpers.php
$transmuted = function_exists('transmute') ? transmute($raw_score) : $raw_score; 

$stmtU = $conn->prepare("
    UPDATE attempts
    SET raw_score=?, transmuted=?, needs_manual_grading=?,
        max_score=?, percentage=?, submitted_at=NOW()
    WHERE id=? AND student_id=? AND submitted_at IS NULL
");

$stmtU->bind_param('iiiidii', $raw_score, $transmuted, $needs_manual, $max_score, $percentage, $attempt_id, $student_id);

if ($stmtU->execute() && $conn->affected_rows > 0) {
    $stmtU->close();
    // Clean up session
    unset($_SESSION['current_attempt_id'], $_SESSION['exam_questions']);
    
    // Redirect to result
    header("Location: result.php?attempt_id=" . $attempt_id);
    exit();
} else {
    die("Submission Error: Attempt already submitted or ID mismatch.");
}