-- ---------- captcha pages are routable ----------
-- ============================================================
-- The captcha-image and captcha-frame pages must be hidden=0. `hidden`
-- doesn't only hide a page from the navbar: a hidden page is a 404 for
-- everyone but admins, and anonymous visitors need these during
-- registration. Like 'avatar' (id 10) and 'WORDING_LOGOUT' (id 19), they are
-- internal but routable. The navbar comes from the `navbar` table, so
-- navigation doesn't change.
--
-- Safe to re-run.
-- ============================================================

UPDATE `page`
   SET `hidden` = 0
 WHERE `url_id` IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- ============================================================
-- VERIFICATION:
--
-- SELECT id, url_id, file_name, template, controller, hidden
--   FROM `page`
--  WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');
--
-- Expected: 2 rows, template=0, controller=1, hidden=0.
-- ============================================================
