<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Purchase = 'purchase';
    /** Worst-case price reserved when a generation is submitted. */
    case Hold = 'hold';
    /** The hold given back once the generation is final. */
    case Release = 'release';
    /** The actual price of a succeeded generation. */
    case Charge = 'charge';
}
