<?php

namespace App\AI\Contracts;

/**
 * SCAFFOLD ONLY — implemented in Milestone 3 (brief §7.4/§7.5, §4.3).
 *
 * Marks the future unknown-product research capability: given a detected
 * identity, search trusted external sources, collect evidence, and return a
 * candidate product + provenance for the validation layer to judge (never
 * auto-truth, §10.2). Left as a marker interface so M3 can slot a Prism-backed
 * implementation behind it with the same "domain depends on the contract"
 * discipline as {@see ProductIdentifier}. No method surface is fixed yet.
 */
interface ProductResearcher {}
