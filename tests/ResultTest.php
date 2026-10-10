<?php

use PHPUnit\Framework\TestCase;

/**
 * The checkout webhook (pawpaymentsresult.php): which payment it is about,
 * what payment.info facts are read, and when payment.setpaid is called.
 * Payment 123 is new (status 1), 25.00 USD, created with a key of ours.
 */
class ResultTest extends TestCase
{
    private const KEY = 'billmgr_method_key_42';

    private function payment(array $over = []): array
    {
        return $over + [
            'module' => 'pmpawpayments',
            'secret_key' => self::KEY,
            'status' => '1',
            'currency' => 'USD',
            'amount' => '25.00',
        ];
    }

    /** [$body, $signature] for a webhook about payment 123. */
    private function webhook(array $payload, string $key = self::KEY): array
    {
        $body = json_encode($payload + ['extra' => '123', 'order_id' => 'po1']);
        return [$body, hash_hmac('sha256', $body, $key)];
    }

    private function act(array $payload, ?array $payment = null, string $key = self::KEY): array
    {
        [$body, $sig] = $this->webhook($payload, $key);
        return PawPaymentsResultAction('123', $body, $sig, $payment ?? $this->payment());
    }

    private function hasLog(array $action, string $level, string $needle): bool
    {
        foreach ($action['logs'] as [$l, $message]) {
            if ($l === $level && strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    // ─── before any mgrctl call ─────────────────────────────────────────────

    public function testOnlyABodyWithAHexDigestLooksLikeAWebhook(): void
    {
        $this->assertTrue(PawPaymentsLooksLikeWebhook('{}', str_repeat('a', 64)));
        $this->assertTrue(PawPaymentsLooksLikeWebhook('{}', str_repeat('A', 64)));
        $this->assertFalse(PawPaymentsLooksLikeWebhook('', str_repeat('a', 64)));
        $this->assertFalse(PawPaymentsLooksLikeWebhook('{}', ''));
        $this->assertFalse(PawPaymentsLooksLikeWebhook('{}', str_repeat('a', 63)));
        $this->assertFalse(PawPaymentsLooksLikeWebhook('{}', str_repeat('g', 64)));
        $this->assertFalse(PawPaymentsLooksLikeWebhook('{}', str_repeat('a', 64) . "\n"));
    }

    public function testTargetIsThePaymentNamedInExtra(): void
    {
        $this->assertSame(['elid' => '123'], PawPaymentsResultTarget('{"extra":"123","status":"success"}'));
        $this->assertSame(['elid' => '123'], PawPaymentsResultTarget('{"extra":123}'));
    }

    /** @dataProvider badTargets */
    public function testBodyThatNamesNoPaymentIsAnsweredWithoutALookup(string $body, array $respond): void
    {
        $this->assertSame($respond, PawPaymentsResultTarget($body)['respond']);
    }

    public function badTargets(): array
    {
        return [
            'not JSON' => ['nope', [400, 'Invalid JSON']],
            'JSON scalar' => ['"123"', [400, 'Invalid JSON']],
            'no extra' => ['{"status":"success"}', [400, 'Missing payment id']],
            'extra not a number' => ['{"extra":"12a"}', [400, 'Missing payment id']],
            'negative extra' => ['{"extra":"-1"}', [400, 'Missing payment id']],
            'permanent-address deposit' => ['{"permanent_address_id":"pa1","extra":"123"}', [200, 'OK']],
        ];
    }

    // ─── payment.info ───────────────────────────────────────────────────────

    public function testPaymentFactsAreReadFromTheMethodTheInvoiceWasCreatedWith(): void
    {
        $info = simplexml_load_string(
            '<doc><payment>'
            . '<id>123</id><status>2</status><paymethodamount>25.00</paymethodamount>'
            . '<currency>3</currency><currency><iso>EUR</iso></currency>'
            . '<paymethod>7</paymethod><paymethod><module>pmpawpayments</module><SECRET_KEY>k7</SECRET_KEY></paymethod>'
            . '</payment></doc>'
        );

        $this->assertSame(
            ['module' => 'pmpawpayments', 'secret_key' => 'k7', 'status' => '2', 'currency' => 'EUR', 'amount' => '25.00'],
            PawPaymentsPaymentFacts($info)
        );
    }

    public function testUnknownPaymentHasNoFacts(): void
    {
        $this->assertNull(PawPaymentsPaymentFacts(false));
        $this->assertNull(PawPaymentsPaymentFacts(null));
        $this->assertNull(PawPaymentsPaymentFacts(simplexml_load_string('<doc/>')));
        $this->assertNull(PawPaymentsPaymentFacts(simplexml_load_string('<doc><payment><id></id></payment></doc>')));
    }

    // ─── who may speak for the payment ──────────────────────────────────────

    public function testUnknownPaymentIs404(): void
    {
        [$body, $sig] = $this->webhook(['status' => 'success']);

        $this->assertSame([404, 'Payment not found'], PawPaymentsResultAction('123', $body, $sig, null)['respond']);
    }

    public function testPaymentOfAnotherModuleIsNeverVerified(): void
    {
        // Its SECRET_KEY is some other gateway's setting: not a key of ours.
        $action = $this->act(['status' => 'success'], $this->payment(['module' => 'pmother']));

        $this->assertSame(401, $action['respond'][0]);
        $this->assertArrayNotHasKey('setpaid', $action);
    }

    public function testMethodWithoutAKeyAnswers503EvenForAMatchingSignature(): void
    {
        $action = $this->act(['status' => 'success'], $this->payment(['secret_key' => '']), '');

        $this->assertSame([503, 'Gateway not configured'], $action['respond']);
        $this->assertArrayNotHasKey('setpaid', $action);
    }

    public function testSignatureMadeWithAnotherMethodsKeyIs401(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 25, 'fiat_currency' => 'USD'], null, 'other_method_key');

        $this->assertSame([401, 'Invalid signature'], $action['respond']);
        $this->assertArrayNotHasKey('setpaid', $action);
    }

    public function testMissingOrderIdIs400(): void
    {
        $body = json_encode(['extra' => '123', 'status' => 'success']);
        $action = PawPaymentsResultAction('123', $body, hash_hmac('sha256', $body, self::KEY), $this->payment());

        $this->assertSame([400, 'Missing order_id'], $action['respond']);
    }

    // ─── success / paid_over ────────────────────────────────────────────────

    public function testPaidWebhookCoveringThePaymentMarksItPaid(): void
    {
        $action = $this->act([
            'status' => 'success', 'fiat_amount' => 25.0, 'initial_fiat_amount' => 25.0, 'fiat_currency' => 'USD', 'payment_url' => 'https://pay/x',
        ]);

        $this->assertSame([200, 'OK'], $action['respond']);
        $this->assertSame(['elid' => '123', 'externalid' => 'po1', 'info' => 'https://pay/x'], $action['setpaid']);
    }

    public function testSetpaidInfoFallsBackToTheOrderId(): void
    {
        $action = $this->act(['status' => 'paid_over', 'fiat_amount' => 30.0, 'initial_fiat_amount' => 25.0, 'fiat_currency' => 'USD']);

        $this->assertSame('po1', $action['setpaid']['info']);
    }

    public function testPaymentInProgressIsStillPayable(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 25.0, 'initial_fiat_amount' => 25.0, 'fiat_currency' => 'USD'], $this->payment(['status' => '2']));

        $this->assertArrayHasKey('setpaid', $action);
    }

    public function testPaidWithinTheMerchantsOwnToleranceMarksItPaid(): void
    {
        // The merchant accepts 20% short (dashboard setting, not in the webhook):
        // `success` for 20 received on a 25.00 invoice pays the payment.
        $action = $this->act(['status' => 'success', 'fiat_amount' => 20.0, 'initial_fiat_amount' => 25.0, 'fiat_currency' => 'USD']);

        $this->assertArrayHasKey('setpaid', $action);
    }

    /** @dataProvider settledStatuses */
    public function testPaymentNoLongerAwaitingPaymentIsNotPaidAgain(string $status): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 25.0, 'fiat_currency' => 'USD'], $this->payment(['status' => $status]));

        $this->assertSame([200, 'Already processed'], $action['respond']);
        $this->assertArrayNotHasKey('setpaid', $action);
    }

    public function settledStatuses(): array
    {
        return [['4'], ['3'], ['5'], ['']];
    }

    /** @dataProvider notCovering */
    public function testWebhookThatDoesNotCoverThePaymentIsRefused(array $payload, array $payment): void
    {
        $action = $this->act($payload + ['status' => 'success'], $this->payment($payment));

        $this->assertSame(409, $action['respond'][0]);
        $this->assertArrayNotHasKey('setpaid', $action);
        $this->assertTrue($this->hasLog($action, 'error', 'not marking paid'));
    }

    public function notCovering(): array
    {
        $paid = ['fiat_amount' => 25.0, 'initial_fiat_amount' => 25.0, 'fiat_currency' => 'USD'];

        return [
            'another currency' => [['fiat_currency' => 'EUR'] + $paid, []],
            // A genuine webhook for a 1.00 invoice whose `extra` names this payment.
            'a cheaper invoice' => [['fiat_amount' => 1.0, 'initial_fiat_amount' => 1.0] + $paid, []],
            'no fiat_currency' => [array_diff_key($paid, ['fiat_currency' => 0]), []],
            'no initial_fiat_amount' => [array_diff_key($paid, ['initial_fiat_amount' => 0]), []],
            'payment currency unknown' => [$paid, ['currency' => '']],
            'payment amount unknown' => [$paid, ['amount' => '']],
        ];
    }

    // ─── statuses that never mark paid ──────────────────────────────────────

    /** @dataProvider nonPayingStatuses */
    public function testStatusNeverMarksPaid(string $status): void
    {
        $action = $this->act(['status' => $status, 'fiat_amount' => 25.0, 'fiat_currency' => 'USD']);

        $this->assertSame([200, 'OK'], $action['respond']);
        $this->assertArrayNotHasKey('setpaid', $action);
    }

    public function nonPayingStatuses(): array
    {
        return [['partially_paid'], ['cancelled'], ['failed'], ['high_risk'], ['']];
    }

    public function testExpiredAfterAPartialAsksForAManualCredit(): void
    {
        // A BillManager payment can only be paid in full, so the kept partial
        // must reach the admin through the log rather than be lost.
        $action = $this->act(['status' => 'cancelled', 'fiat_amount' => 10.0, 'fiat_currency' => 'USD']);

        $this->assertArrayNotHasKey('setpaid', $action);
        $this->assertTrue($this->hasLog($action, 'error', 'credit it manually'));
    }

    public function testExpiredWithNothingPaidIsOnlyDebugLogged(): void
    {
        $action = $this->act(['status' => 'cancelled', 'fiat_amount' => 0, 'fiat_currency' => 'USD']);

        $errors = array_filter($action['logs'], function (array $log): bool {
            return $log[0] === 'error';
        });
        $this->assertSame([], $errors);
    }
}
