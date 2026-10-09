-- =====================================================================
-- School Data Management System: database schema (MySQL / MariaDB)
-- Engine InnoDB, charset utf8mb4.
--
-- On free hosting you cannot run CREATE DATABASE. Create the database in
-- the host's control panel, select it, then import this file.
-- =====================================================================

-- CREATE DATABASE IF NOT EXISTS school_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE school_system;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notifications, messages, gallery, news, grade_scale, marks,
    students, registrations, teacher_assignments, subjects, classes, terms,
    academic_years, teachers, users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. users: everyone who can log in (admin, teacher, head teacher)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(100) NOT NULL,
    username      VARCHAR(50)  NOT NULL,
    email         VARCHAR(100) NOT NULL,
    phone         VARCHAR(20)  NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','teacher','head') NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login    DATETIME     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. teachers: extra details for users whose role is 'teacher'
-- ---------------------------------------------------------------------
CREATE TABLE teachers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    staff_no      VARCHAR(30)  NOT NULL,
    gender        ENUM('male','female') NULL,
    qualification VARCHAR(100) NULL,
    hire_date     DATE         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teachers_user (user_id),
    UNIQUE KEY uq_teachers_staff_no (staff_no),
    CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. academic_years and terms (admin: School settings)
-- ---------------------------------------------------------------------
CREATE TABLE academic_years (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(20) NOT NULL,
    start_date DATE        NOT NULL,
    end_date   DATE        NOT NULL,
    is_current TINYINT(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_year_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE terms (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    academic_year_id INT UNSIGNED NOT NULL,
    name             VARCHAR(30)  NOT NULL,
    start_date       DATE         NOT NULL,
    end_date         DATE         NOT NULL,
    is_current       TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_term (academic_year_id, name),
    CONSTRAINT fk_terms_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. classes and subjects (admin: Classes and subjects)
-- ---------------------------------------------------------------------
CREATE TABLE classes (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name  VARCHAR(30)  NOT NULL,          -- for example 'S1 A'
    level VARCHAR(20)  NOT NULL,          -- for example 'S1'
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80)  NOT NULL,
    code VARCHAR(10)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subject_name (name),
    UNIQUE KEY uq_subject_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. teacher_assignments: which teacher teaches which subject in which class
--    (admin: Assign teachers). One teacher per class and subject per year.
-- ---------------------------------------------------------------------
CREATE TABLE teacher_assignments (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    teacher_id       INT UNSIGNED NOT NULL,
    class_id         INT UNSIGNED NOT NULL,
    subject_id       INT UNSIGNED NOT NULL,
    academic_year_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment (class_id, subject_id, academic_year_id),
    KEY idx_assign_teacher (teacher_id),
    CONSTRAINT fk_assign_teacher FOREIGN KEY (teacher_id)       REFERENCES teachers (id)       ON DELETE CASCADE,
    CONSTRAINT fk_assign_class   FOREIGN KEY (class_id)         REFERENCES classes (id)        ON DELETE CASCADE,
    CONSTRAINT fk_assign_subject FOREIGN KEY (subject_id)       REFERENCES subjects (id)       ON DELETE CASCADE,
    CONSTRAINT fk_assign_year    FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. registrations: online applications from the public Register page
-- ---------------------------------------------------------------------
CREATE TABLE registrations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name     VARCHAR(60)  NOT NULL,
    last_name      VARCHAR(60)  NOT NULL,
    gender         ENUM('male','female') NOT NULL,
    date_of_birth  DATE         NOT NULL,
    previous_school VARCHAR(120) NULL,
    class_applied  VARCHAR(20)  NOT NULL,
    guardian_name  VARCHAR(100) NOT NULL,
    guardian_phone VARCHAR(20)  NOT NULL,
    guardian_email VARCHAR(100) NULL,
    address        VARCHAR(150) NULL,
    status         ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    admin_note     VARCHAR(255) NULL,
    reviewed_by    INT UNSIGNED NULL,
    reviewed_at    DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reg_status (status),
    CONSTRAINT fk_reg_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. students (admin: Students list and Student form)
--    registration_id links a student to the application they came from.
-- ---------------------------------------------------------------------
CREATE TABLE students (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admission_no    VARCHAR(30)  NOT NULL,
    first_name      VARCHAR(60)  NOT NULL,
    last_name       VARCHAR(60)  NOT NULL,
    gender          ENUM('male','female') NOT NULL,
    date_of_birth   DATE         NULL,
    class_id        INT UNSIGNED NOT NULL,
    guardian_name   VARCHAR(100) NULL,
    guardian_phone  VARCHAR(20)  NULL,
    guardian_email  VARCHAR(100) NULL,
    address         VARCHAR(150) NULL,
    photo           VARCHAR(255) NULL,
    status          ENUM('active','graduated','transferred','left') NOT NULL DEFAULT 'active',
    registration_id INT UNSIGNED NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_admission (admission_no),
    KEY idx_students_class (class_id),
    KEY idx_students_name (last_name, first_name),
    CONSTRAINT fk_students_class FOREIGN KEY (class_id)        REFERENCES classes (id)       ON DELETE RESTRICT,
    CONSTRAINT fk_students_reg   FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. marks: one row per student, subject, term and assessment type.
--    Totals, averages, grades and positions are calculated by queries
--    (see the examples at the bottom), never typed in by hand.
-- ---------------------------------------------------------------------
CREATE TABLE marks (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    term_id    INT UNSIGNED NOT NULL,
    assessment ENUM('quiz','test','exam') NOT NULL,
    score      DECIMAL(5,2) NOT NULL,
    max_score  DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    entered_by INT UNSIGNED NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mark (student_id, subject_id, term_id, assessment),
    KEY idx_marks_term_subject (term_id, subject_id),
    CONSTRAINT chk_marks_score CHECK (score >= 0 AND score <= max_score),
    CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
    CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_marks_term    FOREIGN KEY (term_id)    REFERENCES terms (id)    ON DELETE RESTRICT,
    CONSTRAINT fk_marks_user    FOREIGN KEY (entered_by) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. grade_scale: percentage to letter grade (change to the school's scale)
-- ---------------------------------------------------------------------
CREATE TABLE grade_scale (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    grade       VARCHAR(3)   NOT NULL,
    min_percent DECIMAL(5,2) NOT NULL,
    remark      VARCHAR(30)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grade (grade),
    UNIQUE KEY uq_grade_min (min_percent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. news and gallery (admin: News manager, Gallery manager)
-- ---------------------------------------------------------------------
CREATE TABLE news (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title        VARCHAR(150) NOT NULL,
    summary      VARCHAR(255) NULL,
    body         TEXT         NOT NULL,
    image        VARCHAR(255) NULL,
    is_published TINYINT(1)   NOT NULL DEFAULT 1,
    published_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_news_published (is_published, published_at),
    CONSTRAINT fk_news_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gallery (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(120) NOT NULL,
    caption     VARCHAR(255) NULL,
    image_path  VARCHAR(255) NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_gallery_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. messages: from the public Contact page (admin: Contact messages)
-- ---------------------------------------------------------------------
CREATE TABLE messages (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NOT NULL,
    phone      VARCHAR(20)  NULL,
    subject    VARCHAR(150) NOT NULL,
    message    TEXT         NOT NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. notifications: log of emails and SMS the system sends
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel    ENUM('email','sms') NOT NULL,
    recipient  VARCHAR(120) NOT NULL,
    subject    VARCHAR(150) NULL,
    body       TEXT         NOT NULL,
    status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at    DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_notifications_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- EXAMPLE QUERIES (for the teacher and head teacher pages)
-- Use prepared statements in PHP: replace :class_id and :term_id with
-- bound parameters. RANK() needs MySQL 8 or MariaDB 10.2 and later.
-- =====================================================================

-- Class results: total, percentage and position for every student
-- SELECT s.id, s.admission_no, s.first_name, s.last_name,
--        SUM(m.score)     AS total,
--        SUM(m.max_score) AS total_max,
--        ROUND(SUM(m.score) / SUM(m.max_score) * 100, 2) AS percent,
--        RANK() OVER (ORDER BY SUM(m.score) / SUM(m.max_score) DESC) AS position
--   FROM students s
--   JOIN marks m ON m.student_id = s.id
--  WHERE s.class_id = :class_id AND m.term_id = :term_id
--  GROUP BY s.id, s.admission_no, s.first_name, s.last_name
--  ORDER BY position;

-- Letter grade for a percentage
-- SELECT grade FROM grade_scale
--  WHERE min_percent <= :percent
--  ORDER BY min_percent DESC LIMIT 1;

-- Subjects and classes of one teacher (teacher/classes.php)
-- SELECT c.name AS class_name, sub.name AS subject_name
--   FROM teacher_assignments ta
--   JOIN teachers t   ON t.id = ta.teacher_id
--   JOIN classes c    ON c.id = ta.class_id
--   JOIN subjects sub ON sub.id = ta.subject_id
--  WHERE t.user_id = :user_id AND ta.academic_year_id = :year_id;
