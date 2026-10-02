<?php

require_once __DIR__ . '/Result.php';
require_once __DIR__ . '/Gate.php';
require_once __DIR__ . '/Proof.php';
require_once __DIR__ . '/Mutate.php';
require_once __DIR__ . '/Workspace.php';

/**
 * Runs Checks and Proofs. Every Check runs in a child PHP process against a
 * given root, so a mutated copy never collides with classes already loaded here
 * and "the same Check" really is the same code path for baseline and proof.
 */
final class Runner {
    private $root;
    private $entry;

    public function __construct(string $root, string $entry) {
        $this->root = $root;
        $this->entry = $entry; // path of run.php inside $root
    }

    /** Runs a gate's Check in-process. Only the child process / `check` command calls this. */
    public static function runCheck(Gate $gate, string $root): Result {
        try {
            return call_user_func($gate->check, $root);
        } catch (Throwable $e) {
            return Result::refuse('Check crashed: ' . $e->getMessage());
        }
    }

    /** Runs the same Check in a child process against $root. */
    public function checkIn(Gate $gate, string $root): Result {
        $cmd = array(PHP_BINARY, $root . '/guardrails/run.php', 'check', $gate->id, '--json', '--root=' . $root);
        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($proc)) {
            return Result::refuse('could not start child process');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);

        $data = json_decode($out, true);
        if (!is_array($data) || !isset($data['status'])) {
            return Result::refuse('unreadable Check output: ' . trim($err . ' ' . substr($out, 0, 200)));
        }
        return Result::fromArray($data);
    }

    /**
     * @return array{lines:string[], ok:bool, refused:bool}
     */
    public function prove(Gate $gate): array {
        $lines = array();
        $ok = true;

        // A proof against an already-breached or refusing Gate proves nothing.
        $baseline = $this->checkIn($gate, $this->root);
        if ($baseline->status !== Result::PASS) {
            $lines[] = "✗ {$gate->id} / baseline expected=pass actual=" . strtolower($baseline->status)
                . ' — fix the Gate before proving it. ' . $baseline->reason
                . self::describeBreaches($baseline);
            return array('lines' => $lines, 'ok' => false, 'refused' => $baseline->status === Result::REFUSE);
        }

        $proven = array();
        foreach ($gate->proofs as $proof) {
            list($passed, $line) = $this->runProof($gate, $proof);
            $lines[] = ($passed ? '✓ ' : '✗ ') . $line;
            $ok = $ok && $passed;
            if ($passed && $proof->type === Proof::RED) {
                $proven[$proof->rule] = true;
            }
        }

        // A Rule with no passing RED proof is an unproven claim.
        foreach ($gate->rules as $ruleId => $description) {
            if (!isset($proven[$ruleId])) {
                $lines[] = "✗ {$gate->id} / $ruleId UNPROVEN: no RED proof demonstrated this Rule can fail";
                $ok = false;
            }
        }
        return array('lines' => $lines, 'ok' => $ok, 'refused' => false);
    }

    private function runProof(Gate $gate, Proof $proof): array {
        $label = "{$gate->id} / {$proof->name}";
        $work = Workspace::create($this->root);
        try {
            if ($proof->mutation !== null) {
                call_user_func($proof->mutation, $work);
            }
            $result = $this->checkIn($gate, $work);
        } catch (Throwable $e) {
            return array(false, "$label — mutation could not apply: " . $e->getMessage());
        } finally {
            Workspace::destroy($work);
        }

        if ($proof->type === Proof::GREEN) {
            $ok = $result->status === Result::PASS;
            return array($ok, "$label expected=green actual=" . strtolower($result->status) . ($ok ? '' : ' — ' . $result->reason . self::describeBreaches($result)));
        }

        $actual = strtolower($result->status);
        if ($result->status === Result::FAIL && in_array($proof->rule, $result->breachedRules(), true)) {
            return array(true, "$label expected=red actual=$actual\n    breached {$proof->rule}");
        }
        if ($result->status === Result::FAIL) {
            return array(false, "$label — failed, but not on {$proof->rule}; breached: " . implode(', ', $result->breachedRules()));
        }
        return array(false, "$label — mutation was NOT detected (actual=$actual) {$result->reason}");
    }

    private static function describeBreaches(Result $result): string {
        $parts = array();
        foreach (array_slice($result->breaches, 0, 5) as $b) {
            $parts[] = "\n    breached {$b['rule']}: {$b['message']}";
        }
        return implode('', $parts);
    }
}
