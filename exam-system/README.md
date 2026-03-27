# Exam System — MCQ + Fill-in-the-Blank (.docx upload)

This build supports both multiple-choice (MCQ) and fill-in-the-blank (short answer) questions imported from .docx.
- MCQ format (same as before):
  1. Question?
  A) Option A
  B) Option B
  C) Option C
  D) Option D
  Answer: B
- Fill format:
  1. tool tips and mouse settings.
  Answer: Tooltips
or without answer (for manual grading):
  2. comes up automatically...
  (no Answer: line) -> teacher must manually grade

If any of the 50 selected questions in an attempt require manual grading, the attempt will be marked `needs_manual_grading` and raw/transmuted scores will be blank until teacher grades it.

Setup: import schema.sql, edit db.php, deploy on PHP server.
