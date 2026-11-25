-- ============================================================
-- AstrX migration: download page for the key-login client (core — module = '')
-- ============================================================
-- WORDING_ZKP_CLIENT ('zkp_client', ZkpClientController) serves zkp_client.py
-- as a file. The login, sign-up and settings pages link to it, so a visitor
-- can get the client from the site itself.
--
-- template = 0: the controller writes the file and exits. hidden = 0: a hidden
-- page is a 404 for everyone but admins, and visitors need it before they have
-- an account. No navbar entry, and robots index = 0. Safe to re-run.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ZKP_CLIENT', 1, 'zkp_client', 0, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ZKP_CLIENT';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ZKP_CLIENT';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ZKP_CLIENT';
