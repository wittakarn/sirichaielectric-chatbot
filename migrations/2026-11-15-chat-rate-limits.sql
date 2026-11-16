CREATE TABLE IF NOT EXISTS chat_rate_limits (
    token_id     CHAR(16) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (token_id, window_start)
) ENGINE=InnoDB;
