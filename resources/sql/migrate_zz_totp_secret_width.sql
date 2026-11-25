-- The encrypted TOTP envelope exceeds the original 64-character column.
ALTER TABLE `user` MODIFY COLUMN `totp_secret` VARCHAR(255) NULL;
