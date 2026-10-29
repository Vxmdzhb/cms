-- Optional Schnorr credentials. No existing password or account is changed.
CREATE TABLE IF NOT EXISTS `user_zkp_key` (
    `user_id` BINARY(16) NOT NULL PRIMARY KEY,
    `public_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    CONSTRAINT `fk_zkp_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Retain consumed rows until expiry for bounded issuance and race-safe replay rejection.
CREATE TABLE IF NOT EXISTS `user_zkp_challenge` (
    `nonce` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    `session_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `action` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `origin` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expires` BIGINT NOT NULL,
    `used` TINYINT NOT NULL DEFAULT 0,
    INDEX `zkp_session` (`session_hash`),
    INDEX `zkp_expiry` (`expires`)
) ENGINE=InnoDB;
