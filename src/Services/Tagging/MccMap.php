<?php

declare(strict_types=1);

namespace App\Services\Tagging;

/**
 * ISO 18245 merchant category codes -> our categories.
 *
 * Federal embeds the MCC as the last segment of a UPI narration
 * ("UPIOUT/523161283382/paytmqr.../ Pay-/5411"), which is a free, high-accuracy
 * signal the merchant-name rules can't beat. Consulted after the rule table so
 * a user's explicit rule always wins.
 */
final class MccMap
{
    private const MAP = [
        // groceries & food retail
        '5411' => 'grocery', '5412' => 'grocery', '5422' => 'grocery',
        '5441' => 'grocery', '5451' => 'grocery', '5462' => 'grocery', '5499' => 'grocery',
        // eating out
        '5812' => 'food_dining', '5813' => 'food_dining', '5814' => 'food_dining',
        // transport & fuel
        '4111' => 'transport_fuel', '4112' => 'transport_fuel', '4121' => 'transport_fuel',
        '4131' => 'transport_fuel', '5541' => 'transport_fuel', '5542' => 'transport_fuel',
        '7523' => 'transport_fuel',
        // travel  (4131 = intercity bus; kept above under transport_fuel)
        '3000' => 'travel', '4511' => 'travel', '4722' => 'travel', '7011' => 'travel',
        // utilities & telecom
        '4900' => 'utility', '4899' => 'utility',
        '4812' => 'telecom_internet', '4814' => 'telecom_internet', '4816' => 'telecom_internet',
        // healthcare
        '5912' => 'healthcare', '8011' => 'healthcare', '8021' => 'healthcare',
        '8031' => 'healthcare', '8042' => 'healthcare', '8062' => 'healthcare', '8099' => 'healthcare',
        // shopping
        '5311' => 'shopping', '5399' => 'shopping', '5611' => 'shopping', '5621' => 'shopping',
        '5651' => 'shopping', '5661' => 'shopping', '5691' => 'shopping', '5732' => 'shopping',
        '5733' => 'shopping', '5942' => 'shopping', '5944' => 'shopping', '5977' => 'shopping',
        // entertainment & subscriptions
        '5815' => 'subscription', '5816' => 'subscription', '5817' => 'subscription', '5818' => 'subscription',
        '5945' => 'entertainment', '7832' => 'entertainment', '7841' => 'entertainment',
        '7922' => 'entertainment', '7994' => 'entertainment', '7996' => 'entertainment',
        // services
        '6300' => 'insurance', '8220' => 'education', '8211' => 'education', '8241' => 'education',
        '7230' => 'personal_care', '7297' => 'personal_care', '7298' => 'personal_care',
        '8398' => 'charity_gift', '8661' => 'charity_gift',
        '9311' => 'tax', '9399' => 'tax',
        '6011' => 'cash_withdrawal',
    ];

    public static function category(?string $mcc): ?string
    {
        if ($mcc === null) {
            return null;
        }

        return self::MAP[$mcc] ?? null;
    }
}
