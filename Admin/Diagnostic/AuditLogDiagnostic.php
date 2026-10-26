<?php
declare(strict_types=1);

namespace AstrX\Admin\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when an audit log entry can't be written. A warning: the action
 * itself succeeded.
 */
final class AuditLogDiagnostic extends AbstractDiagnostic
{

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }

    public function detail(): string { return $this->detail; }
}