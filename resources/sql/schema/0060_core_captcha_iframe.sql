-- ---------- captcha iframe pages ----------
-- ============================================================
-- The page rows for the reloadable captcha. Uses only columns of the base
-- schema, so it works whichever migrations have run.
--
-- Safe to re-run.
-- ============================================================

-- 1. The two page rows. Default api_enabled=0 is fine if the column exists;
--    if it doesn't, the column is simply not referenced.
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_CAPTCHA_IMAGE', 1, 'captcha_image', 0, 1, 0, 0),
    ('WORDING_CAPTCHA_FRAME', 1, 'captcha_frame', 0, 1, 0, 0);

-- 2. Closure self-references (used by the routing layer for ancestor lookups).
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- 3. Page meta — both hidden from search engines via page_robots.
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- ============================================================
-- VERIFICATION (run separately to check state):
--
-- SELECT id, url_id, file_name, template, controller, hidden
--   FROM `page`
--  WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');
--
-- Expected output: 2 rows, both with template=0, controller=1, hidden=1.
-- If you get 0 rows, this migration didn't succeed — check the schema
-- of the `page` table and re-run.
-- ============================================================
