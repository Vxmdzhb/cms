<?php
declare(strict_types=1);

namespace AstrX\Admin\Controller;

use AstrX\Admin\Diagnostic\AdminDbDiagnostic;
use AstrX\Auth\Gate;
use AstrX\Config\Config;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\UrlGenerator;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\TemplateVars;
use PDO;
use AstrX\Result\DiagnosticLevel;
use AstrX\Kernel\AbstractController;
use AstrX\Admin\AdminPermission;

/**
 * Full management for all three navbars (public=1, user=2, admin=3).
 *
 * Each navbar contains one or more PINS (groups). Each pin has:
 *   - sort_order: position of this group within the navbar
 *   - sort_mode:  0 = entries sorted alphabetically
 *                 1 = entries sorted by custom sort_order
 *
 * Each pin contains one or more ENTRIES (internal page links or external URLs).
 *
 * UI flow:
 *   /en/admin-navbar                → lists all three navbars, tabs
 *   /en/admin-navbar?nb=2           → user navbar tab active
 *   /en/admin-navbar?nb=2&pin=3     → pin 3 open for editing
 *   /en/admin-navbar?nb=2&pin=3&entry=5 → entry 5 in edit mode
 */
final class AdminNavbarController extends AbstractController
{
    private const FORM = 'admin_navbar';


    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly TemplateVars $ctx,
        private readonly Request               $request,
        private readonly PDO                   $pdo,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Page                  $page,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    // =========================================================================
    // Handle
    // =========================================================================

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(AdminPermission::NAVBAR)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        // Self-URL: works in both rewrite (/en/admin-banlist) and query mode.
        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        // PRG dispatch
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $posted = $this->prg->pull($prgToken) ?? [];
            try {
                $this->processForm($posted);
            } catch (\PDOException $e) {
                $this->emitDiag($e);
                $this->flash->set('error', $this->t->t('admin.db_error'));
            }
            $navbar = max(1, min(3, self::mInt($posted, 'navbar_id', 1)));
            Response::redirect($this->urlGen->toPage($resolvedUrlId, ['nb' => $navbar]))
                ->send()->drainTo($this->collector);
            exit;
        }

        $nbRaw2 = $this->request->query()->get('nb');
        $activeNavbar = max(1, min(3, is_int($nbRaw2) ? $nbRaw2 : (is_numeric($nbRaw2) ? (int)$nbRaw2 : 1)));
        $pinRaw2 = $this->request->query()->get('pin');
        $activePin    = is_int($pinRaw2) ? $pinRaw2 : (is_numeric($pinRaw2) ? (int)$pinRaw2 : 0);
        $entryRaw2 = $this->request->query()->get('entry');
        $activeEntry  = is_int($entryRaw2) ? $entryRaw2 : (is_numeric($entryRaw2) ? (int)$entryRaw2 : 0);

        // Build full data tree for all three navbars
        $navbars = $this->loadAllNavbars();

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);
        $pages     = $this->loadPages();


        $this->ctx->set('csrf_token',     $csrfToken);
        $this->ctx->set('prg_id',         $prgId);
        $this->ctx->set('available_pages', $pages);

        // Decorate navbars: active flag, navbar_id, pin_id, editing flags
        /** @var list<array<string,mixed>> $navbars */
        foreach ($navbars as &$nb) {
            /** @var array<string,mixed> $nb */
            $nb['active'] = ($nb['id'] === $activeNavbar);
            $pins = is_array($nb['pins'] ?? null) ? $nb['pins'] : [];
            /** @var array<string,mixed> $pin */
            foreach ($pins as &$pin) {
                /** @var array<string,mixed> $pin */
                $pin['navbar_id'] = $nb['id'];
                $nbId  = self::mInt($nb, 'id');
                $pinId = self::mInt($pin, 'id');
                $pin['edit_url']   = $this->urlGen->toPage($resolvedUrlId, ['nb' => $nbId, 'pin' => $pinId]);
                $pin['cancel_url'] = $this->urlGen->toPage($resolvedUrlId, ['nb' => $nbId]);
                $pinIsEditing = ($pin['id'] === $activePin && $nb['id'] === $activeNavbar);
                $pin['editing'] = $pinIsEditing && $activeEntry === 0;
                $entries = is_array($pin['entries'] ?? null) ? $pin['entries'] : [];
                foreach ($entries as &$entry) {
                    /** @var array<string,mixed> $entry */
                    $entry['navbar_id'] = $nb['id'];
                    $entry['pin_id']    = $pin['id'];
                    $entry['edit_url']   = $this->urlGen->toPage($resolvedUrlId, ['nb' => $nbId, 'pin' => $pinId, 'entry' => self::mInt($entry, 'id')]);
                    $entry['cancel_url'] = $pin['edit_url'];
                    if ($pinIsEditing && $entry['id'] === $activeEntry) {
                        // Mark the selected page option
                        $editingPageId = self::mInt($entry, 'page_id', 0);
                        $pagesWithSel = [];
                        foreach ($pages as $pg) {
                            $pg['selected'] = (self::mInt($pg, 'id') === $editingPageId);
                            $pagesWithSel[] = $pg;
                        }
                        $entry['available_pages'] = $pagesWithSel;
                        $entry['editing'] = true;
                    } else {
                        $entry['editing'] = false;
                    }
                }
                unset($entry);
                $pin['entries'] = $entries;
            }
            unset($pin);
            $nb['pins'] = $pins;
        }
        unset($nb);
        $this->ctx->set('navbars', $navbars);

        // Navbar tab labels
        $this->ctx->set('nb_public', $this->t->t('admin.navbar.nb_public'));
        $this->ctx->set('nb_user',   $this->t->t('admin.navbar.nb_user'));
        $this->ctx->set('nb_admin',  $this->t->t('admin.navbar.nb_admin'));

        $this->setI18n();
        return $this->ok();
    }

    // =========================================================================
    // Form processing
    // =========================================================================

    /** @param array<string,mixed> $posted */
    private function processForm(array $posted): void
    {
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action    = self::mStr($posted, 'action', '');
        $navbarId  = max(1, min(3, self::mInt($posted, 'navbar_id', 1)));
        $pinId     = self::mInt($posted, 'pin_id', 0);
        $entryId   = self::mInt($posted, 'entry_id', 0);

        switch ($action) {
            // ── Pin actions ───────────────────────────────────────────────────
            case 'add_pin':
                $sortOrder = self::mInt($posted, 'sort_order', 0);
                $sortMode  = self::mInt($posted, 'sort_mode', 0);
                $this->addPin($navbarId, $sortOrder, $sortMode);
                $this->flash->set('success', $this->t->t('admin.navbar.pin_added'));
                break;

            case 'update_pin':
                $sortOrder = self::mInt($posted, 'sort_order', 0);
                $sortMode  = self::mInt($posted, 'sort_mode', 0);
                $this->updatePin($pinId, $sortOrder, $sortMode);
                $this->flash->set('success', $this->t->t('admin.navbar.pin_updated'));
                break;

            case 'delete_pin':
                $this->deletePin($pinId);
                $this->flash->set('success', $this->t->t('admin.navbar.pin_deleted'));
                break;

            // ── Entry actions ─────────────────────────────────────────────────
            case 'add_entry':
            case 'update_entry':
                $name      = trim(self::mStr($posted, 'name', ''));
                $type      = self::mStr($posted, 'type', 'external');
                $url       = trim(self::mStr($posted, 'url', ''));
                $pageId    = self::mInt($posted, 'page_id', 0);
                $sortOrder = self::mInt($posted, 'sort_order', 0);
                $active    = self::mBool($posted, 'active') ? 1 : 0;
                $i18n      = self::mBool($posted, 'i18n')   ? 1 : 0;
                if ($name === '') {
                    // Explicit feedback on missing required field.
                    $this->flash->set('error', $this->t->t('admin.navbar.name_required'));
                    break;
                }
                if ($type === 'internal' && $pageId <= 0) {
                    $this->flash->set('error', $this->t->t('admin.navbar.page_required'));
                    break;
                }
                if ($action === 'add_entry') {
                    $this->addEntry($pinId, $name, $i18n, $type, $url, $pageId, $sortOrder, $active);
                    $this->flash->set('success', $this->t->t('admin.navbar.added'));
                } else {
                    $this->updateEntry($entryId, $name, $i18n, $type, $url, $pageId, $sortOrder, $active);
                    $this->flash->set('success', $this->t->t('admin.navbar.updated'));
                }
                break;

            case 'toggle_entry':
                $this->toggleEntry($entryId);
                break;

            case 'delete_entry':
                $this->deleteEntry($entryId);
                $this->flash->set('success', $this->t->t('admin.navbar.deleted'));
                break;
        }
    }

    // =========================================================================
    // Data loading
    // =========================================================================

    /**
     * Returns data structure for all navbars:
     * [ navbar_id => [ 'name' => '...', 'pins' => [ pin_id => ['sort_order'=>..., 'sort_mode'=>..., 'entries'=>[...]] ] ] ]
     */
    /** @return array<int,array<string,mixed>> */
    private function loadAllNavbars(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT n.id AS navbar_id, n.name AS navbar_name,
                        p.id AS pin_id, p.sort_order AS pin_sort_order, p.sort_mode,
                        e.id AS entry_id, e.name AS entry_name, e.i18n, e.internal,
                        e.active, e.sort_order AS entry_sort_order,
                        ni.page_id, ne.url, pg.url_id AS page_url_id
                   FROM navbar n
                   LEFT JOIN navbar_pin p       ON p.navbar_id = n.id
                   LEFT JOIN navbar_entry e      ON e.pin_id   = p.id
                   LEFT JOIN navbar_internal ni  ON ni.id      = e.id
                   LEFT JOIN navbar_external ne  ON ne.id      = e.id
                   LEFT JOIN page pg             ON pg.id      = ni.page_id
                   ORDER BY n.id, p.sort_order, e.sort_order, e.name'
            );
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Also surface to user via flash so it appears in the UI.
            $this->emitDiag($e);
            $this->flash->set('error', $this->t->t('admin.db_error'));
            return [];
        }

        $navbars = [];
        foreach ($rows as $row) {
            $nid = self::mInt($row, 'navbar_id');
            $rawPinId = $row['pin_id'];
            $pid = $rawPinId !== null ? (is_int($rawPinId) ? $rawPinId : (is_numeric($rawPinId) ? (int)$rawPinId : null)) : null;
            $rawEntryId = $row['entry_id'];
            $eid = $rawEntryId !== null ? (is_int($rawEntryId) ? $rawEntryId : (is_numeric($rawEntryId) ? (int)$rawEntryId : null)) : null;

            if (!isset($navbars[$nid])) {
                $navbars[$nid] = [
                    'id'   => $nid,
                    'name' => $row['navbar_name'],
                    'pins' => [],
                ];
            }
            if ($pid !== null && !isset($navbars[$nid]['pins'][$pid])) {
                $navbars[$nid]['pins'][$pid] = [
                    'id'         => $pid,
                    'sort_order' => self::mInt($row, 'pin_sort_order'),
                    'sort_mode'  => self::mInt($row, 'sort_mode'),
                    'alpha'      => self::mInt($row, 'sort_mode') === 0,
                    'custom'     => self::mInt($row, 'sort_mode') === 1,
                    'entries'    => [],
                ];
            }
            if ($pid !== null && $eid !== null) {
                $navbars[$nid]['pins'][$pid]['entries'][] = [
                    'id'         => $eid,
                    'name'       => $row['entry_name'],
                    'i18n'       => (bool) $row['i18n'],
                    'internal'   => (bool) $row['internal'],
                    'external'   => !(bool) $row['internal'],
                    'active'     => (bool) $row['active'],
                    'sort_order' => self::mInt($row, 'entry_sort_order'),
                    'page_id'    => $row['page_id'] !== null ? self::mInt($row, 'page_id') : null,
                    'page_url_id'=> $row['page_url_id'],
                    'url'        => $row['url'],
                ];
            }
        }

        // Convert pin maps to lists for Mustache iteration
        foreach ($navbars as &$nb) {
            $nb['pins'] = array_values($nb['pins']);
        }
        unset($nb);

        return array_values($navbars);
    }

    /** @return list<array<string,mixed>> */
    private function loadPages(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT p.id, p.url_id, p.file_name, pm.title
                   FROM page p
                   LEFT JOIN page_meta pm ON pm.page_id = p.id
                  WHERE p.hidden = 0
                  ORDER BY p.id'
            );
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows;
        } catch (\PDOException) {
            return [];
        }
    }

    // =========================================================================
    // DB writes — pins
    // =========================================================================

    private function addPin(int $navbarId, int $sortOrder, int $sortMode): void
    {
        $this->pdo->prepare(
            'INSERT INTO navbar_pin (navbar_id, sort_order, sort_mode)
             VALUES (:nb, :so, :sm)'
        )->execute([':nb' => $navbarId, ':so' => $sortOrder, ':sm' => $sortMode]);
    }

    private function updatePin(int $id, int $sortOrder, int $sortMode): void
    {
        $this->pdo->prepare(
            'UPDATE navbar_pin SET sort_order = :so, sort_mode = :sm WHERE id = :id'
        )->execute([':so' => $sortOrder, ':sm' => $sortMode, ':id' => $id]);
    }

    private function deletePin(int $id): void
    {
        // CASCADE deletes entries and their internal/external subtypes
        $this->pdo->prepare('DELETE FROM navbar_pin WHERE id = :id')
            ->execute([':id' => $id]);
    }

    // =========================================================================
    // DB writes — entries
    // =========================================================================

    private function addEntry(int $pinId, string $name, int $i18n, string $type,
        string $url, int $pageId, int $sortOrder, int $active): void
    {
        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('INSERT INTO navbar_entry_ids () VALUES ()');
            $newId      = (int) $this->pdo->lastInsertId();
            $isInternal = $type === 'internal' ? 1 : 0;

            $this->pdo->prepare(
                'INSERT INTO navbar_entry (id, pin_id, internal, name, i18n, active, sort_order)
                 VALUES (:id, :pin, :int, :name, :i18n, :active, :sort)'
            )->execute([':id' => $newId, ':pin' => $pinId, ':int' => $isInternal,
                        ':name' => $name, ':i18n' => $i18n,
                        ':active' => $active, ':sort' => $sortOrder]);

            if ($isInternal) {
                $this->pdo->prepare(
                    'INSERT INTO navbar_internal (id, page_id) VALUES (:id, :pid)'
                )->execute([':id' => $newId, ':pid' => $pageId]);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO navbar_external (id, url) VALUES (:id, :url)'
                )->execute([':id' => $newId, ':url' => $url]);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    private function updateEntry(int $id, string $name, int $i18n, string $type,
        string $url, int $pageId, int $sortOrder, int $active): void
    {
        // Wrap in transaction — type switches between internal/external
        // require coordinated DELETE+INSERT across two subtype tables.
        try {
            $this->pdo->beginTransaction();
            $isInternal = $type === 'internal' ? 1 : 0;
            $this->pdo->prepare(
                'UPDATE navbar_entry
                    SET name = :name, i18n = :i18n, internal = :int,
                        active = :active, sort_order = :sort
                  WHERE id = :id'
            )->execute([':name' => $name, ':i18n' => $i18n, ':int' => $isInternal,
                        ':active' => $active, ':sort' => $sortOrder, ':id' => $id]);

            if ($isInternal) {
                $this->pdo->prepare('DELETE FROM navbar_external WHERE id = :id')
                    ->execute([':id' => $id]);
                $this->pdo->prepare(
                    'INSERT INTO navbar_internal (id, page_id) VALUES (:id, :pid)
                     ON DUPLICATE KEY UPDATE page_id = :pid2'
                )->execute([':id' => $id, ':pid' => $pageId, ':pid2' => $pageId]);
            } else {
                $this->pdo->prepare('DELETE FROM navbar_internal WHERE id = :id')
                    ->execute([':id' => $id]);
                $this->pdo->prepare(
                    'INSERT INTO navbar_external (id, url) VALUES (:id, :url)
                     ON DUPLICATE KEY UPDATE url = :url2'
                )->execute([':id' => $id, ':url' => $url, ':url2' => $url]);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    private function toggleEntry(int $id): void
    {
        $this->pdo->prepare('UPDATE navbar_entry SET active = 1 - active WHERE id = :id')
            ->execute([':id' => $id]);
    }

    private function deleteEntry(int $id): void
    {
        $this->pdo->prepare('DELETE FROM navbar_entry_ids WHERE id = :id')
            ->execute([':id' => $id]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function setI18n(): void
    {
        $this->ctx->set('admin_navbar_heading', $this->t->t('admin.nav.navbar'));
        $this->ctx->set('label_name',      $this->t->t('admin.field.name'));
        $this->ctx->set('label_type',      $this->t->t('admin.field.type'));
        $this->ctx->set('label_url',       $this->t->t('admin.navbar.url'));
        $this->ctx->set('label_page',      $this->t->t('admin.field.page'));
        $this->ctx->set('label_active',    $this->t->t('admin.field.active'));
        $this->ctx->set('label_sort',      $this->t->t('admin.navbar.sort'));
        $this->ctx->set('label_sort_mode', $this->t->t('admin.navbar.sort_mode'));
        $this->ctx->set('label_i18n',      $this->t->t('admin.navbar.i18n'));
        $this->ctx->set('label_actions',   $this->t->t('admin.field.actions'));
        $this->ctx->set('label_pins',      $this->t->t('admin.navbar.pins'));
        $this->ctx->set('label_entries',   $this->t->t('admin.navbar.entries'));
        $this->ctx->set('type_internal',   $this->t->t('admin.navbar.type_internal'));
        $this->ctx->set('type_external',   $this->t->t('admin.navbar.type_external'));
        $this->ctx->set('sort_alpha',      $this->t->t('admin.navbar.sort_alpha'));
        $this->ctx->set('sort_custom',     $this->t->t('admin.navbar.sort_custom'));
        $this->ctx->set('btn_add',         $this->t->t('admin.btn.add'));
        $this->ctx->set('btn_update',      $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_delete',      $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_edit',        $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_cancel',      $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_toggle',      $this->t->t('admin.btn.toggle'));
        $this->ctx->set('btn_add_pin',     $this->t->t('admin.navbar.btn_add_pin'));
        $this->ctx->set('btn_add_entry',   $this->t->t('admin.navbar.btn_add_entry'));
    }

    private function emitDiag(\PDOException $e): void
    {
        $this->emit(new AdminDbDiagnostic(
                        'astrx.admin/db_error', DiagnosticLevel::ERROR, $e->getMessage()
                    ));
    }
}
