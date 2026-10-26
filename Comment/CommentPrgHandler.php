<?php

declare(strict_types = 1);

namespace AstrX\Comment;

use AstrX\Session\AbstractPrgHandler;

/**
 * A PRG namespace of its own for comment forms.
 *
 * Separate session keys (COMMENT_POST_ / COMMENT_TARGET_) and a separate
 * query key (_cp), so a page controller that shares the main _prg key (e.g.
 * UserController) can't consume a comment's redirect before
 * CommentController sees it.
 *
 * Everything else, including garbage collection of abandoned payloads and
 * their uploaded files, lives in AbstractPrgHandler.
 */
final class CommentPrgHandler extends AbstractPrgHandler
{
    private const string POST_PREFIX = 'COMMENT_POST_';
    /** MUST start with POST_PREFIX — see AbstractPrgHandler::postMetaPrefix(). */
    private const string POST_META_PREFIX = 'COMMENT_POST_META_';
    private const string TARGET_PREFIX = 'COMMENT_TARGET_';

    public const string QUERY_KEY = '_cp';

    protected function postPrefix(): string     { return self::POST_PREFIX; }
    protected function postMetaPrefix(): string { return self::POST_META_PREFIX; }
    protected function targetPrefix(): string   { return self::TARGET_PREFIX; }

    public function tokenQueryKey(): string     { return self::QUERY_KEY; }
}
