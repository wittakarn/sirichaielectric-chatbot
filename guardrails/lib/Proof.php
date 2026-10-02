<?php

/**
 * RED   plants a violation of $rule; the Check must FAIL and name that rule.
 * GREEN leaves (or benignly changes) the project; the Check must PASS.
 */
final class Proof {
    const RED = 'red';
    const GREEN = 'green';

    public $type;
    public $rule;
    public $name;
    /** @var callable|null function(string $root): void, throws on a mutation that cannot apply */
    public $mutation;

    private function __construct(string $type, ?string $rule, string $name, ?callable $mutation) {
        $this->type = $type;
        $this->rule = $rule;
        $this->name = $name;
        $this->mutation = $mutation;
    }

    public static function red(string $rule, string $name, callable $mutation): self {
        return new self(self::RED, $rule, $name, $mutation);
    }

    public static function green(string $name, ?callable $mutation = null): self {
        return new self(self::GREEN, null, $name, $mutation);
    }
}
