-- 1. Authentication Table (Simplified)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','teacher','student') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Student Profile Table (Detailed Info)
CREATE TABLE IF NOT EXISTS students (
    user_id INT PRIMARY KEY, -- Links directly to users(id)
    first_name VARCHAR(120) NOT NULL,
    middle_initial VARCHAR(10) NULL,
    last_name VARCHAR(120) NOT NULL,
    course VARCHAR(50) NOT NULL,
    year_section VARCHAR(20) NOT NULL,
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Exam System Tables (Keep these as they are correct)
CREATE TABLE IF NOT EXISTS exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_by),
  CONSTRAINT fk_exams_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  type ENUM('mcq','fill') NOT NULL DEFAULT 'mcq',
  question_text TEXT NOT NULL,
  option_a TEXT,
  option_b TEXT,
  option_c TEXT,
  option_d TEXT,
  correct_answer ENUM('A','B','C','D') NULL,
  answer_text TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (exam_id),
  INDEX (created_by),
  CONSTRAINT fk_questions_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_questions_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  student_id INT NOT NULL,
  selected_question_ids TEXT NOT NULL,
  raw_score INT DEFAULT NULL,
  transmuted INT DEFAULT NULL,
  needs_manual_grading TINYINT(1) DEFAULT 0,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  INDEX (exam_id),
  INDEX (student_id),
  CONSTRAINT fk_attempts_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  -- attempts still links to users(id) because attempts is for ALL users (teachers/admins could technically take an exam too, though student_id enforces student role)
  CONSTRAINT fk_attempts_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attempt_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  selected_answer TEXT NULL,
  is_correct TINYINT(1) NULL,
  INDEX (attempt_id),
  INDEX (question_id),
  CONSTRAINT fk_attempt_answers_attempt FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_attempt_answers_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The 'student_answers' table you included is redundant if you are using 'attempt_answers'.
-- I recommend dropping it to simplify the system:
-- DROP TABLE student_answers;