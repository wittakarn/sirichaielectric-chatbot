#!/usr/bin/env php
<?php

/**
 * Guardrail CLI (a PHP take on https://github.com/schalermthai/redproof).
 *
 *   php guardrails/run.php check    [gate-id ...]   run the Checks against the real tree
 *   php guardrails/run.php prove    [gate-id ...]   prove each Check can fail
 *   php guardrails/run.php describe [gate-id ...]   list Rules, Checks and Proofs
 *
 * Exit codes: 0 all good, 1 a Rule is breached or a Proof failed, 2 refused.
 */

require_once __DIR__ . '/lib/Runner.php';

$args = array_slice($argv, 1);
$command = array_shift($args) ?: 'check';
$json = false;
$root = realpath(__DIR__ . '/..');
$selected = array();
foreach ($args as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (strpos($arg, '--root=') === 0) {
        $root = substr($arg, 7);
    } else {
        $selected[] = $arg;
    }
}

/** @var Gate[] $gates */
$gates = array();
foreach (glob(__DIR__ . '/gates/*.php') as $file) {
    $gate = require $file;
    $gates[$gate->id] = $gate;
}
foreach ($selected as $id) {
    if (!isset($gates[$id])) {
        fwrite(STDERR, "No Gate matched: $id\n");
        exit(2); // a typo must never turn into an empty, green run
    }
}
if ($selected) {
    $gates = array_intersect_key($gates, array_flip($selected));
}

if ($command === 'describe') {
    foreach ($gates as $gate) {
        echo "Gate: {$gate->id}\n  Rules:\n";
        foreach ($gate->rules as $id => $desc) {
            echo "    $id  $desc\n";
        }
        echo "  Check: {$gate->checkDescription}\n  Proofs:\n";
        foreach ($gate->proofs as $p) {
            echo '    ' . strtoupper($p->type) . ($p->rule ? " {$p->rule}" : '') . ": {$p->name}\n";
        }
        echo "\n";
    }
    exit(0);
}

if ($command === 'check') {
    if ($json) { // child-process mode: exactly one Gate, machine-readable
        echo json_encode(Runner::runCheck(reset($gates), $root)->toArray());
        exit(0);
    }
    $exit = 0;
    foreach ($gates as $gate) {
        $result = Runner::runCheck($gate, $root);
        $mark = $result->status === Result::PASS ? '✓' : '✗';
        echo "$mark {$gate->id} {$result->status} (inspected {$result->inspected})";
        echo $result->reason ? " — {$result->reason}" : '';
        echo "\n";
        foreach ($result->breaches as $b) {
            echo "    breached {$b['rule']}: {$b['message']}\n";
        }
        if ($result->status === Result::REFUSE) {
            $exit = max($exit, 2);
        } elseif ($result->status === Result::FAIL) {
            $exit = max($exit, 1);
        }
    }
    exit($exit);
}

if ($command === 'prove') {
    $runner = new Runner($root, __FILE__);
    $exit = 0;
    foreach ($gates as $gate) {
        $outcome = $runner->prove($gate);
        echo implode("\n", $outcome['lines']) . "\n";
        if (!$outcome['ok']) {
            $exit = max($exit, $outcome['refused'] ? 2 : 1);
        }
    }
    exit($exit);
}

fwrite(STDERR, "Unknown command: $command (use check | prove | describe)\n");
exit(2);
