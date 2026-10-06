CREATE TABLE roles (
  id TINYINT UNSIGNED PRIMARY KEY,
  name VARCHAR(16) NOT NULL UNIQUE,
  CONSTRAINT chk_role CHECK (name IN ('Admin','User'))
) ENGINE=InnoDB;
INSERT INTO roles VALUES (1,'Admin'),(2,'User');

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id TINYINT UNSIGNED NOT NULL DEFAULT 2,
  email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  token_version INT UNSIGNED NOT NULL DEFAULT 0,
  failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('todo','in-progress','done') NOT NULL DEFAULT 'todo',
  priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  due_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tasks_owner_status_id (user_id,status,id DESC),
  KEY idx_tasks_owner_id (user_id,id DESC),
  KEY idx_tasks_status_id (status,id DESC),
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_title CHECK (CHAR_LENGTH(TRIM(title)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Prepare/deallocate demonstrates executable query templates without running sample reads at initialization.
-- EXPLAIN: unique email lookup (const); roles joins by PRIMARY (const/eq_ref); at most one user.
PREPARE login_query FROM 'SELECT u.*,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=?';
DEALLOCATE PREPARE login_query;
-- EXPLAIN: idx_tasks_owner_status_id uses equality prefixes and ordered id scan; no filesort expected.
PREPARE user_tasks FROM 'SELECT * FROM tasks WHERE user_id=? AND status=? ORDER BY id DESC LIMIT ? OFFSET ?';
DEALLOCATE PREPARE user_tasks;
-- EXPLAIN: idx_tasks_status_id supplies Admin status filtering and order; primary-row reads fetch payload.
PREPARE admin_tasks FROM 'SELECT * FROM tasks WHERE status=? ORDER BY id DESC LIMIT ? OFFSET ?';
DEALLOCATE PREPARE admin_tasks;
-- Plans depend on statistics/selectivity; verify with EXPLAIN ANALYZE on representative data.
