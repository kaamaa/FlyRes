-- FRes_api_tokens: Bearer-Tokens fuer die FlutterFlow-App (parallel zu Cookie-Auth)
-- Manuell ausfuehren: mysql -u <user> -p <database> < sql/2026-06-26-api-tokens.sql

CREATE TABLE FRes_api_tokens (
    id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED      NOT NULL,
    token_hash    CHAR(64)          NOT NULL,
    device_name   VARCHAR(100)      DEFAULT NULL,
    user_agent    VARCHAR(255)      DEFAULT NULL,
    last_ip       VARCHAR(45)       DEFAULT NULL,
    created_at    DATETIME          NOT NULL,
    last_used_at  DATETIME          DEFAULT NULL,
    expires_at    DATETIME          DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token_hash (token_hash),
    KEY idx_user_last_used (user_id, last_used_at),
    CONSTRAINT fk_api_tokens_user
        FOREIGN KEY (user_id) REFERENCES FRes_accounts (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
