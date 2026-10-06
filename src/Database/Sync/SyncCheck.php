<?php

declare(strict_types=1);

namespace Campanella\Database\Sync;

use Campanella\Database\Installer;
use Campanella\Http\Request;
use Campanella\System\CheckResult;
use Campanella\System\CheckStatus;
use Closure;

/**
 * The system check's lines about the objects' capabilities against their
 * Blueprints (the schema's own differences are listed by SystemCheck).
 */
final class SyncCheck
{
    /** @return Closure(?Request): list<CheckResult> For SystemCheck::add() */
    public static function checks(Installer $installer, SchemaSync $sync): Closure
    {
        return static function (?Request $request) use ($installer, $sync): array {
            try {
                if (!$installer->isInstalled()) {
                    return [];
                }
                $plan = $sync->plan();
            } catch (\Throwable) {
                return [];
            }
            $results = [];
            foreach ($plan->steps as $step) {
                $status = match ($step->kind) {
                    SyncStepKind::AddCapability => CheckStatus::Warning,
                    SyncStepKind::Blocked => CheckStatus::Error,
                    SyncStepKind::Note => CheckStatus::Info,
                    default => null, // schema steps: already listed as differences
                };
                if ($status !== null) {
                    $results[] = new CheckResult(
                        'admin.system.group.schema',
                        'admin.system.sync.' . $step->kind->value,
                        $status,
                        (string) ($step->message->params['blueprint'] ?? $step->message->params['table'] ?? ''),
                        $step->message,
                    );
                }
            }

            return $results;
        };
    }
}
