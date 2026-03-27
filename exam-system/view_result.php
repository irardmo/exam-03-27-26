<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

require_login();
require_role('student');

$attempt_id = (int)($_GET['attempt_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM attempts WHERE id=? AND student_id=?");
$stmt->bind_param('ii', $attempt_id, $_SESSION['user']['id']);
$stmt->execute();
$result = $stmt->get_result();
$attempt = $result->fetch_assoc();

if (!$attempt) {
    die("Result not found.");
}

// Decode answers JSON
$answers = json_decode($attempt['answers'], true) ?? [];

// Fetch questions with correct answers
$stmt2 = $conn->prepare("SELECT id, question, type, option_a, option_b, option_c, option_d, correct_option, answer_text 
                         FROM questions WHERE exam_id=?");
$stmt2->bind_param('i', $attempt['exam_id']);
$stmt2->execute();
$res2 = $stmt2->get_result();
$questions = $res2->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Exam Result</title>
    <link rel="stylesheet" href="style-all.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .correct { color: green; font-weight: bold; }
        .wrong { color: red; font-weight: bold; }
        .question-box { border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; border-radius: 8px; }
        .answer { margin-top: 5px; }
    </style>
</head>
<body>
    <h2>Your Exam Result</h2>
    <p><strong>Score:</strong> <?= $attempt['score'] ?> / <?= $attempt['max_score'] ?></p>
    <p><strong>Percentage:</strong> <?= round($attempt['percentage'], 2) ?>%</p>
    <p><strong>Transmuted Grade:</strong> <?= round($attempt['transmuted'], 2) ?></p>

    <h3>Question Review</h3>
    <?php foreach ($questions as $q): 
        $student_answer = $answers[$q['id']] ?? '';
        $is_correct = false;

        if ($q['type'] === 'mcq') {
            $is_correct = (strcasecmp($student_answer, $q['correct_option']) === 0);
        } else {
            $is_correct = (strcasecmp(trim($student_answer), trim($q['answer_text'])) === 0);
        }
    ?>
    <div class="question-box">
        <p><strong>Q:</strong> <?= htmlspecialchars($q['question']) ?></p>

        <?php if ($q['type'] === 'mcq'): ?>
            <ul>
                <li>A. <?= htmlspecialchars($q['option_a']) ?></li>
                <li>B. <?= htmlspecialchars($q['option_b']) ?></li>
                <li>C. <?= htmlspecialchars($q['option_c']) ?></li>
                <li>D. <?= htmlspecialchars($q['option_d']) ?></li>
            </ul>
        <?php endif; ?>

        <p class="answer">✅ <strong>Your Answer:</strong> 
            <span class="<?= $is_correct ? 'correct' : 'wrong' ?>">
                <?= htmlspecialchars($student_answer ?: 'No Answer') ?>
            </span>
        </p>

        <p class="answer">📌 <strong>Correct Answer:</strong> 
            <span class="correct">
                <?= htmlspecialchars($q['type'] === 'mcq' ? $q['correct_option'] : $q['answer_text']) ?>
            </span>
        </p>
    </div>
    <?php endforeach; ?>

    <a href="student_dashboard.php">⬅ Back to Dashboard</a>
</body>
</html>
<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

require_login();
require_role('student');

$attempt_id = (int)($_GET['attempt_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM attempts WHERE id=? AND student_id=?");
$stmt->bind_param('ii', $attempt_id, $_SESSION['user']['id']);
$stmt->execute();
$result = $stmt->get_result();
$attempt = $result->fetch_assoc();

if (!$attempt) {
    die("Result not found.");
}

// Decode answers JSON
$answers = json_decode($attempt['answers'], true) ?? [];

// Fetch questions with correct answers
$stmt2 = $conn->prepare("SELECT id, question, type, option_a, option_b, option_c, option_d, correct_option, answer_text 
                         FROM questions WHERE exam_id=?");
$stmt2->bind_param('i', $attempt['exam_id']);
$stmt2->execute();
$res2 = $stmt2->get_result();
$questions = $res2->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Exam Result</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .correct { color: green; font-weight: bold; }
        .wrong { color: red; font-weight: bold; }
        .question-box { border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; border-radius: 8px; }
        .answer { margin-top: 5px; }
    </style>
</head>
<body>
    <h2>Your Exam Result</h2>
    <p><strong>Score:</strong> <?= $attempt['score'] ?> / <?= $attempt['max_score'] ?></p>
    <p><strong>Percentage:</strong> <?= round($attempt['percentage'], 2) ?>%</p>
    <p><strong>Transmuted Grade:</strong> <?= round($attempt['transmuted'], 2) ?></p>

    <h3>Question Review</h3>
    <?php foreach ($questions as $q): 
        $student_answer = $answers[$q['id']] ?? '';
        $is_correct = false;

        if ($q['type'] === 'mcq') {
            $is_correct = (strcasecmp($student_answer, $q['correct_option']) === 0);
        } else {
            $is_correct = (strcasecmp(trim($student_answer), trim($q['answer_text'])) === 0);
        }
    ?>
    <div class="question-box">
        <p><strong>Q:</strong> <?= htmlspecialchars($q['question']) ?></p>

        <?php if ($q['type'] === 'mcq'): ?>
            <ul>
                <li>A. <?= htmlspecialchars($q['option_a']) ?></li>
                <li>B. <?= htmlspecialchars($q['option_b']) ?></li>
                <li>C. <?= htmlspecialchars($q['option_c']) ?></li>
                <li>D. <?= htmlspecialchars($q['option_d']) ?></li>
            </ul>
        <?php endif; ?>

        <p class="answer">✅ <strong>Your Answer:</strong> 
            <span class="<?= $is_correct ? 'correct' : 'wrong' ?>">
                <?= htmlspecialchars($student_answer ?: 'No Answer') ?>
            </span>
        </p>

        <p class="answer">📌 <strong>Correct Answer:</strong> 
            <span class="correct">
                <?= htmlspecialchars($q['type'] === 'mcq' ? $q['correct_option'] : $q['answer_text']) ?>
            </span>
        </p>
    </div>
    <?php endforeach; ?>

    <a href="student_dashboard.php">⬅ Back to Dashboard</a>
</body>
</html>
