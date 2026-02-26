CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    typo3_version VARCHAR(20) NOT NULL,
    php_version VARCHAR(10) NOT NULL,
    event_type ENUM('new_install', 'install', 'update') NOT NULL,
    project_hash CHAR(16) NOT NULL,
    os VARCHAR(20) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    country CHAR(2) DEFAULT NULL,
    latitude DECIMAL(8, 4) DEFAULT NULL,
    longitude DECIMAL(9, 4) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_project (project_hash),
    INDEX idx_version (typo3_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_hash CHAR(64) PRIMARY KEY,
    request_count INT UNSIGNED DEFAULT 1,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
