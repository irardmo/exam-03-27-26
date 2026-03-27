<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
// Include header here, as it's required for the HTML structure
include 'header.php'; 

require_login();
require_role('student');

$attempt_id = (int)($_GET['attempt_id'] ?? 0);
$student_id = $_SESSION['user']['id'];

if ($attempt_id <= 0) {
    redirect('student_dashboard.php');
    exit;
}

/* 1) Fetch attempt with exam (SECURE) */
// FIX 1: Ensure the attempt has been submitted (submitted_at IS NOT NULL) before showing results
$stmt = $conn->prepare("
    SELECT a.*, e.title, e.description
    FROM attempts a
    JOIN exams e ON a.exam_id = e.id
    WHERE a.id = ? AND a.student_id = ? AND a.submitted_at IS NOT NULL
    LIMIT 1
");
$stmt->bind_param('ii', $attempt_id, $student_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attempt) {
    // Redirect with an error if attempt is not found or not submitted
    $_SESSION['error'] = "Result not available or attempt not submitted.";
    redirect('student_dashboard.php');
    exit;
}

/* 2) Fetch answers linked to this attempt (SECURE) */
// FIX 2: Add q.answer_text to the SELECT list for non-MCQ display
$stmt2 = $conn->prepare("
    SELECT q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
           q.correct_answer, q.answer_text, q.type,
           aa.selected_answer, aa.is_correct
    FROM attempt_answers aa
    JOIN questions q ON aa.question_id = q.id
    WHERE aa.attempt_id = ?
    ORDER BY aa.id ASC
");

if (!$stmt2) {
    die("Result fetch prepare failed: " . $conn->error);
}

$stmt2->bind_param('i', $attempt_id);
$stmt2->execute();
$answersRes = $stmt2->get_result();
$stmt2->close();

/* helper: turn letter (A/B/C/D) into full option text */
function option_text_for_letter($row, $letter) {
    $letter = strtoupper(trim($letter));
    $map = ['A'=>'option_a','B'=>'option_b','C'=>'option_c','D'=>'option_d'];
    // Return the full option text if mapped, otherwise return the letter itself
    return isset($map[$letter]) ? ($row[$map[$letter]] ?? $letter) : $letter;
}
?>

<div class="card">
    <h2>Exam Result: <?= htmlspecialchars($attempt['title']) ?></h2>
    <?php if (!empty($attempt['description'])): ?>
        <p><?= nl2br(htmlspecialchars($attempt['description'])) ?></p>
    <?php endif; ?>

    <div class="summary">
        <strong>Score:</strong> <?= (int)$attempt['raw_score'] ?> / <?= (int)$attempt['max_score'] ?><br>
        <strong>Percentage:</strong> <?= number_format((float)$attempt['percentage'], 2) ?>%<br>
        <strong>Transmuted Grade:</strong> <?= number_format((float)$attempt['transmuted'], 2) ?><br>
        <strong>Date Submitted:</strong>
        <?php
        $exam_date = $attempt['submitted_at'] ?? null;
        if ($exam_date) {
            $date_obj = new DateTime($exam_date);
            echo $date_obj->format('F j, Y H:i');
        } else {
            // Should be unreachable due to submitted_at check in query
            echo 'Error: Unsubmitted'; 
        }
        ?><br>
    </div>

    <h3>Question Breakdown (Your Answers vs. Correct Answers)</h3>
    <table class="table">
        <thead>
            <tr>
                <th style="width:40%">Question</th>
                <th style="width:20%">Your Answer</th>
                <th style="width:20%">Correct Answer</th>
                <th style="width:10%">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($answersRes && $answersRes->num_rows): ?>
            <?php while ($r = $answersRes->fetch_assoc()): ?>
                <?php
                    $student_answer = $r['selected_answer'] ?? ''; 
                    $qtype = strtolower($r['type'] ?? 'mcq');

                    if ($qtype === 'mcq') {
                        // Display student's selected option text
                        $student_display = option_text_for_letter($r, $student_answer);
                        // Display correct option text
                        $correct_display = option_text_for_letter($r, $r['correct_answer']);
                    } else {
                        // For fill/text, both answers are just text fields.
                        $student_display = $student_answer;
                        // FIX: Use answer_text for the correct display of non-MCQ types
                        $correct_display = $r['answer_text'] ?? 'Manual Grading Required'; 
                    }

                    $is_correct = (isset($r['is_correct']) && $r['is_correct'] == 1);
                ?>
                <tr>
                    <td><pre><?= htmlspecialchars($r['question_text']) ?></pre></td>
                    <td><?= nl2br(htmlspecialchars($student_display)) ?></td>
                    <td><?= nl2br(htmlspecialchars($correct_display)) ?></td>
                    <td><?= $is_correct ? '<span class="correct">Correct</span>' : '<span class="wrong">Wrong</span>' ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="4">No answers found for this attempt.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <p><a href="student_dashboard.php" class="btn">Back to Dashboard</a></p>
</div>

<?php include 'footer.php'; ?>