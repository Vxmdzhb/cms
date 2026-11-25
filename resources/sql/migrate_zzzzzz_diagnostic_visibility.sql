-- Let visitors and members see the messages that are written for them.
--
-- The message bar shows a diagnostic to a group only when diagnostic_visibility
-- lists it for that group (ADMIN always sees everything). The table was never
-- filled, so on every install guests and members saw no message at all: a
-- wrong password, a mistyped captcha or an expired form simply showed the form
-- again. This lists the user-facing messages for GUEST, USER and MOD; internal
-- ones (configuration, database details, the injector) stay admin-only.
--
-- A code that already has a row was set in Admin → Access and is left alone.
-- Re-running is harmless.

-- Everyone: sign-in, sign-up, recovery, captcha, forms and comments.
INSERT INTO `diagnostic_visibility` (`code`, `group_name`)
SELECT c.code, g.group_name
  FROM (SELECT 'astrx.user/login_failed' AS code
      UNION ALL SELECT 'astrx.user/login_restricted'
      UNION ALL SELECT 'astrx.user/not_verified'
      UNION ALL SELECT 'astrx.user/registration_closed'
      UNION ALL SELECT 'astrx.user/identifier_unavailable'
      UNION ALL SELECT 'astrx.user/password_too_short'
      UNION ALL SELECT 'astrx.user/invalid_username'
      UNION ALL SELECT 'astrx.user/invalid_password'
      UNION ALL SELECT 'astrx.user/invalid_mailbox'
      UNION ALL SELECT 'astrx.user/passwords_mismatch'
      UNION ALL SELECT 'astrx.user/invalid_date'
      UNION ALL SELECT 'astrx.user/too_young'
      UNION ALL SELECT 'astrx.user/empty_fields'
      UNION ALL SELECT 'astrx.user/wrong_password'
      UNION ALL SELECT 'astrx.user/token_not_found'
      UNION ALL SELECT 'astrx.user/token_expired'
      UNION ALL SELECT 'astrx.user/token_already_sent'
      UNION ALL SELECT 'astrx.user/not_found'
      UNION ALL SELECT 'astrx.user/invalid_theme'
      UNION ALL SELECT 'astrx.user/key_proof_rejected'
      UNION ALL SELECT 'astrx.user/db_error'
      UNION ALL SELECT 'astrx.user/unknown'
      UNION ALL SELECT 'astrx.user/avatar_extension'
      UNION ALL SELECT 'astrx.user/avatar_invalid'
      UNION ALL SELECT 'astrx.user/avatar_size'
      UNION ALL SELECT 'astrx.user/avatar_upload_error'
      UNION ALL SELECT 'astrx.user/avatar_unknown'
      UNION ALL SELECT 'astrx.user/avatar_move_failed'
      UNION ALL SELECT 'astrx.captcha/not_found'
      UNION ALL SELECT 'astrx.captcha/expired'
      UNION ALL SELECT 'astrx.captcha/wrong_text'
      UNION ALL SELECT 'astrx.captcha/regenerate_failed'
      UNION ALL SELECT 'astrx.csrf/token_missing'
      UNION ALL SELECT 'astrx.csrf/token_mismatch'
      UNION ALL SELECT 'astrx.csrf/token_expired'
      UNION ALL SELECT 'astrx.session/invalid_prg_id'
      UNION ALL SELECT 'astrx.panic/locked'
      UNION ALL SELECT 'astrx.comment/db_error'
      UNION ALL SELECT 'astrx.comment/not_allowed'
      UNION ALL SELECT 'astrx.comment/flood'
      UNION ALL SELECT 'astrx.comment/antispam'
      UNION ALL SELECT 'astrx.comment/empty_content'
      UNION ALL SELECT 'astrx.comment/reply_not_found'
      UNION ALL SELECT 'astrx.comment/reply_wrong_page'
      UNION ALL SELECT 'astrx.comment/invalid_email'
      UNION ALL SELECT 'astrx.comment/not_found'
      UNION ALL SELECT 'astrx.comment/muted'
      UNION ALL SELECT 'astrx.comment/gate_denied'
      UNION ALL SELECT 'astrx.comment/unknown'
      UNION ALL SELECT 'astrx.http/upload_error'
      UNION ALL SELECT 'astrx.image/too_big'
      UNION ALL SELECT 'astrx.image/bad_type'
      UNION ALL SELECT 'astrx.image/undecodable') c
 CROSS JOIN (SELECT 'GUEST' AS group_name UNION ALL SELECT 'USER' UNION ALL SELECT 'MOD') g
 WHERE NOT EXISTS (SELECT 1 FROM `diagnostic_visibility` v WHERE v.`code` = c.code);

-- Signed-in members: webmail and API keys.
INSERT INTO `diagnostic_visibility` (`code`, `group_name`)
SELECT c.code, g.group_name
  FROM (SELECT 'astrx.mail/imap.connect' AS code
      UNION ALL SELECT 'astrx.mail/imap.command'
      UNION ALL SELECT 'astrx.mail/imap.fetch'
      UNION ALL SELECT 'astrx.mail/imap.append'
      UNION ALL SELECT 'astrx.mail/imap.starttls'
      UNION ALL SELECT 'astrx.mail/send_failed'
      UNION ALL SELECT 'astrx.mail/invalid_recipient'
      UNION ALL SELECT 'astrx.mail/error'
      UNION ALL SELECT 'astrx.mail/trust.add_failed'
      UNION ALL SELECT 'astrx.mail/trust.check_failed'
      UNION ALL SELECT 'astrx.mail/trust.list_failed'
      UNION ALL SELECT 'astrx.mail/trust.remove_failed'
      UNION ALL SELECT 'astrx.api/key_create_failed'
      UNION ALL SELECT 'astrx.api/key_label_required'
      UNION ALL SELECT 'astrx.api/key_label_too_long'
      UNION ALL SELECT 'astrx.api/key_create_forbidden'
      UNION ALL SELECT 'astrx.api/key_revoke_forbidden') c
 CROSS JOIN (SELECT 'USER' AS group_name UNION ALL SELECT 'MOD') g
 WHERE NOT EXISTS (SELECT 1 FROM `diagnostic_visibility` v WHERE v.`code` = c.code);
