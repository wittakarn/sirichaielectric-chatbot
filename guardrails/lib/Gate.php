<?php

/**
 * A guardrail: Rules (id => description), one Check that evaluates them all,
 * and Proofs that demonstrate the Check can really fail.
 *
 * The Check receives the project root it must inspect. It is a parameter, not
 * a constant, so proofs can run the very same Check against a mutated copy.
 */
final class Gate {
    public $id;
    /** @var array<string,string> */
    public $rules;
    public $checkDescription;
    /** @var callable(string):Result */
    public $check;
    /** @var Proof[] */
    public $proofs;

    public function __construct(string $id, array $rules, string $checkDescription, callable $check, array $proofs) {
        $this->id = $id;
        $this->rules = $rules;
        $this->checkDescription = $checkDescription;
        $this->check = $check;
        $this->proofs = $proofs;
    }
}
