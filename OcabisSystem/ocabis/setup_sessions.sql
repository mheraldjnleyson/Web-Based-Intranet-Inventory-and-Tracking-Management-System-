-- Create user_sessions table to track active sessions
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `session_id` varchar(128) NOT NULL,
    `ip_address` varchar(45) NOT NULL,
    `user_agent` text,
    `login_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `session_id` (`session_id`),
    KEY `user_id` (`user_id`),
    KEY `is_active` (`is_active`),
    KEY `last_activity` (`last_activity`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create index for faster lookups
CREATE INDEX idx_user_active_sessions ON user_sessions(user_id, is_active);

-- Clean up old sessions (older than 30 days)
DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY);
