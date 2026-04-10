<?php
// export_results.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
session_start();

// 1. Security Check
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    die("Unauthorized access.");
}

$teacher_id = (int)$_SESSION['user']['id'];

// 2. Get Filter Parameters (Match the names used in your Teacher Dashboard)
$f_exam    = $_GET['f_exam'] ?? '';
$f_course  = $_GET['f_course'] ?? '';
$f_section = $_GET['f_section'] ?? '';
$f_period  = $_GET['f_period'] ?? '';

// 3. Set Headers for CSV Download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Exam_Results_' . date('Y-m-d_Hi') . '.csv"');

// 4. Open Output Stream
$output = fopen('php://output', 'w');

// 5. Add CSV Header Row
fputcsv($output, [
    'Last Name', 
    'First Name', 
    'M.I.', 
    'Course', 
    'Year & Section', 
    'Subject/Exam Title', 
    'Raw Score', 
    'Max Score', 
    'Rating/Grade', 
    'Date Submitted'
]);

// 6. Build Dynamic Query based on Filters
$sql = "
    SELECT 
        st.first_name,
        st.middle_initial,
        st.last_name,
        st.course,
        st.year_section,
        u.username,
        e.title AS exam_title,
        a.raw_score,
        a.max_score,
        a.submitted_at
    FROM attempts a
    JOIN exams e ON a.exam_id = e.id
    JOIN users u ON a.student_id = u.id
    LEFT JOIN students st ON u.id = st.user_id 
    WHERE e.created_by = $teacher_id 
    AND a.submitted_at IS NOT NULL
";

// Apply Subject Filter
if (!empty($f_exam)) {
    $sql .= " AND e.id = " . (int)$f_exam;
}

// Apply Course Filter
if (!empty($f_course)) {
    $course_safe = $conn->real_escape_string($f_course);
    $sql .= " AND st.course = '$course_safe'";
}

// Apply Section Filter
if (!empty($f_section)) {
    $section_safe = $conn->real_escape_string($f_section);
    $sql .= " AND st.year_section = '$section_safe'";
}

// Apply Period Filter
if (!empty($f_period)) {
    $period_safe = $conn->real_escape_string($f_period);
    $sql .= " AND e.description = '$period_safe'";
}

$sql .= " ORDER BY st.last_name ASC, st.first_name ASC";

$results = $conn->query($sql);

// 7. Loop through results and write to CSV
if ($results && $results->num_rows > 0) {
    while ($row = $results->fetch_assoc()) {
        
        // Fallback: If student profile is missing, use username as Last Name
        $lastName  = !empty($row['last_name']) ? $row['last_name'] : $row['username'];
        $firstName = !empty($row['first_name']) ? $row['first_name'] : 'N/A';
        
        // Calculate Rating (Transmute)
        // Note: Assumes transmute() is a globally accessible helper function
        $grade = transmute($row['raw_score']); 

        fputcsv($output, [
            $lastName,
            $firstName,
            $row['middle_initial'] ?? '',
            $row['course'] ?? 'N/A',
            $row['year_section'] ?? 'N/A',
            $row['exam_title'],
            $row['raw_score'],
            $row['max_score'],
            number_format((float)$grade, 2),
            date('M d, Y h:i A', strtotime($row['submitted_at']))
        ]);
    }
} else {
    // Optional: Add a row saying no data if the file is empty
    // fputcsv($output, ['No results found matching your filters.']);
}

// 8. Close stream
fclose($output);
exit();