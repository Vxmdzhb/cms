-- ---------- banlist_ip.prefix_len holds /128 ----------
-- ============================================================
-- IPv4 addresses are stored as IPv4-mapped IPv6 networks (::ffff:a.b.c.d),
-- so a single IPv4 address is a /128 (32 + 96). A signed TINYINT stops at
-- 127, so the column is TINYINT UNSIGNED (0-255). Existing values are kept.
-- Idempotent: modifying to the same type does nothing.
-- ============================================================

ALTER TABLE `banlist_ip` MODIFY COLUMN `prefix_len` TINYINT UNSIGNED NOT NULL;

-- ============================================================
-- VERIFICATION:
--   SHOW COLUMNS FROM `banlist_ip` LIKE 'prefix_len';   -- Type: tinyint(3) unsigned
-- ============================================================
