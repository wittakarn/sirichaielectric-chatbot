<?php

/**
 * Outcome of one Check run.
 *   PASS    every Rule held
 *   FAIL    one or more Rules were breached
 *   REFUSE  the Check could not decide safely (e.g. it inspected nothing)
 */
final class Result {
    const PASS = 'PASS';
    const FAIL = 'FAIL';
    const REFUSE = 'REFUSE';

    public $status;
    public $inspected;
    /** @var array<int, array{rule:string, message:string}> */
    public $breaches;
    public $reason;

    private function __construct(string $status, int $inspected, array $breaches, string $reason) {
        $this->status = $status;
        $this->inspected = $inspected;
        $this->breaches = $breaches;
        $this->reason = $reason;
    }

    /**
     * Zero inspected targets can never establish that a Rule holds, so a
     * would-be PASS becomes REFUSE (a Check that silently looks at nothing is
     * the classic way a guardrail rots).
     */
    public static function fromBreaches(int $inspected, array $breaches): self {
        if ($inspected === 0) {
            return new self(self::REFUSE, 0, array(), 'Check inspected zero targets');
        }
        return new self(count($breaches) > 0 ? self::FAIL : self::PASS, $inspected, $breaches, '');
    }

    public static function refuse(string $reason): self {
        return new self(self::REFUSE, 0, array(), $reason);
    }

    public static function breach(string $rule, string $message): array {
        return array('rule' => $rule, 'message' => $message);
    }

    public function toArray(): array {
        return array('status' => $this->status, 'inspected' => $this->inspected, 'breaches' => $this->breaches, 'reason' => $this->reason);
    }

    public static function fromArray(array $data): self {
        return new self($data['status'], (int)$data['inspected'], $data['breaches'], (string)$data['reason']);
    }

    public function breachedRules(): array {
        return array_values(array_unique(array_column($this->breaches, 'rule')));
    }
}
