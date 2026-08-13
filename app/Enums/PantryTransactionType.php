<?php

namespace App\Enums;

/**
 * The kind of movement recorded in the immutable pantry ledger
 * (BUILD_PLAN §5, idea #5; brief §7.10/§8.7).
 *
 * `purchase` adds stock; `consume`/`discard`/`manual_remove` remove it;
 * `correction` sets the balance to a known-true absolute value (its delta can
 * be positive or negative).
 */
enum PantryTransactionType: string
{
    case Purchase = 'purchase';
    case Consume = 'consume';
    case Discard = 'discard';
    case Correction = 'correction';
    case ManualRemove = 'manual_remove';
}
