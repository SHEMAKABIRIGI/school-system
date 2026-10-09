-- =====================================================================
-- Draft data for testing only. Replace with real data later.
-- Import schema.sql first, then this file.
--
-- Demo logins (password for all three is: ChangeMe123)
--   admin   -> username 'admin'
--   teacher -> username 'teacher1', 'teacher2'
--   head    -> username 'head'
-- DELETE these accounts before the system holds real data.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO users (id, full_name, username, email, phone, password_hash, role) VALUES
(1, 'Draft Admin',        'admin',    'admin@example.com',    NULL, '$2y$10$iU28APhLnn0WgG9fcQB1O.QX/cfk2tG3FaEeQvdfBrsv5FJfLlrJu', 'admin'),
(2, 'Draft Teacher One',  'teacher1', 'teacher1@example.com', NULL, '$2y$10$iU28APhLnn0WgG9fcQB1O.QX/cfk2tG3FaEeQvdfBrsv5FJfLlrJu', 'teacher'),
(3, 'Draft Teacher Two',  'teacher2', 'teacher2@example.com', NULL, '$2y$10$iU28APhLnn0WgG9fcQB1O.QX/cfk2tG3FaEeQvdfBrsv5FJfLlrJu', 'teacher'),
(4, 'Draft Head Teacher', 'head',     'head@example.com',     NULL, '$2y$10$iU28APhLnn0WgG9fcQB1O.QX/cfk2tG3FaEeQvdfBrsv5FJfLlrJu', 'head');

INSERT INTO teachers (id, user_id, staff_no, gender, qualification) VALUES
(1, 2, 'T-001', 'male',   'Bachelor of Education'),
(2, 3, 'T-002', 'female', 'Bachelor of Education');

INSERT INTO academic_years (id, name, start_date, end_date, is_current) VALUES
(1, '2026', '2026-01-12', '2026-11-27', 1);

INSERT INTO terms (id, academic_year_id, name, start_date, end_date, is_current) VALUES
(1, 1, 'Term 1', '2026-01-12', '2026-04-10', 0),
(2, 1, 'Term 2', '2026-05-04', '2026-08-07', 0),
(3, 1, 'Term 3', '2026-09-07', '2026-11-27', 1);

INSERT INTO classes (id, name, level) VALUES
(1, 'S1 A', 'S1'),
(2, 'S1 B', 'S1'),
(3, 'S2 A', 'S2');

INSERT INTO subjects (id, name, code) VALUES
(1, 'Mathematics', 'MATH'),
(2, 'English',     'ENG'),
(3, 'Kinyarwanda', 'KIN'),
(4, 'Physics',     'PHY'),
(5, 'Biology',     'BIO'),
(6, 'History',     'HIS');

INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, academic_year_id) VALUES
(1, 1, 1, 1),
(1, 2, 1, 1),
(2, 1, 2, 1),
(2, 2, 2, 1);

INSERT INTO students (id, admission_no, first_name, last_name, gender, class_id, guardian_name, guardian_phone) VALUES
(1, 'ADM-0001', 'Draft', 'Student 1', 'female', 1, 'Draft Guardian', '0780000001'),
(2, 'ADM-0002', 'Draft', 'Student 2', 'male',   1, 'Draft Guardian', '0780000002'),
(3, 'ADM-0003', 'Draft', 'Student 3', 'female', 1, 'Draft Guardian', '0780000003'),
(4, 'ADM-0004', 'Draft', 'Student 4', 'male',   1, 'Draft Guardian', '0780000004'),
(5, 'ADM-0005', 'Draft', 'Student 5', 'female', 2, 'Draft Guardian', '0780000005'),
(6, 'ADM-0006', 'Draft', 'Student 6', 'male',   3, 'Draft Guardian', '0780000006');

INSERT INTO marks (student_id, subject_id, term_id, assessment, score, max_score, entered_by) VALUES
(1, 1, 3, 'test', 42, 50, 2), (1, 1, 3, 'exam', 78, 100, 2),
(2, 1, 3, 'test', 35, 50, 2), (2, 1, 3, 'exam', 64, 100, 2),
(3, 1, 3, 'test', 28, 50, 2), (3, 1, 3, 'exam', 55, 100, 2),
(4, 1, 3, 'test', 45, 50, 2), (4, 1, 3, 'exam', 88, 100, 2),
(1, 2, 3, 'test', 40, 50, 3), (1, 2, 3, 'exam', 70, 100, 3),
(2, 2, 3, 'test', 30, 50, 3), (2, 2, 3, 'exam', 60, 100, 3),
(3, 2, 3, 'test', 33, 50, 3), (3, 2, 3, 'exam', 58, 100, 3),
(4, 2, 3, 'test', 41, 50, 3), (4, 2, 3, 'exam', 81, 100, 3);

-- Sample scale only. Change it to the grading scale your school uses.
INSERT INTO grade_scale (grade, min_percent, remark) VALUES
('A', 80, 'Excellent'),
('B', 70, 'Very good'),
('C', 60, 'Good'),
('D', 50, 'Satisfactory'),
('E', 40, 'Pass'),
('F', 0,  'Fail');

INSERT INTO registrations (first_name, last_name, gender, date_of_birth, class_applied, guardian_name, guardian_phone, status) VALUES
('Draft', 'Applicant 1', 'female', '2013-03-14', 'S1', 'Draft Guardian', '0780000010', 'pending'),
('Draft', 'Applicant 2', 'male',   '2012-11-02', 'S1', 'Draft Guardian', '0780000011', 'pending');

INSERT INTO news (title, summary, body, created_by) VALUES
('Welcome to our new website', 'Our school website is now online.', 'This is draft news text. Replace it with real news.', 1),
('Term 3 begins', 'Term 3 has started.', 'This is draft news text. Replace it with real news.', 1);

INSERT INTO messages (name, email, subject, message) VALUES
('Draft Visitor', 'visitor@example.com', 'Test message', 'This is a draft contact message.');
