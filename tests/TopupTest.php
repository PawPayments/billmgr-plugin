<?php

use PHPUnit\Framework\TestCase;

/**
 * The legacy top-up webhook (pawpaymentstopup_result.php): which method's key
 * verifies it, and when payment.add credits an account. Two PawPayments
 * methods unless a test says otherwise; method 2 keeps balances in USD.
 */
class TopupTest extends TestCase
{
    /** @var string[] accounts the already-credited lookup was asked about */
    private $lookups = [];

    protected function setUp(): void
    {
        $this->lookups = [];
    }

    private function methods(): array
    {
        return [
            ['id' => '1', 'secret_key' => 'key_one', 'topup_currency' => 'EUR'],
            ['id' => '2', 'secret_key' => 'key_two', 'topup_currency' => 'USD'],
        ];
    }

    private function act(array $payload, string $key = 'key_two', ?array $methods = null, array $credited = []): array
    {
        $body = json_encode($payload + ['extra' => '77', 'order_id' => 'po1']);
        $creditedOrderIds = function (string $accountId) use ($credited): array {
            $this->lookups[] = $accountId;
            return $credited;
        };

        return PawPaymentsTopupAction($body, hash_hmac('sha256', $body, $key), $methods ?? $this->methods(), $creditedOrderIds);
    }

    public function testDepositVerifiedByAMethodIsCreditedInItsTopupCurrency(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.5, 'fiat_currency' => 'USD']);

        $this->assertSame([200, 'OK'], $action['respond']);
        $this->assertSame(
            ['account' => '77', 'amount' => '40.5', 'externalid' => 'po1', 'note' => 'Crypto deposit po1'],
            $action['add']
        );
        $this->assertSame(['77'], $this->lookups);
    }

    public function testTheVerifyingMethodDecidesTheCurrency(): void
    {
        // Signed with method 1's key: its balances are in EUR, so a USD deposit
        // is refused even though method 2 would have accepted it.
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], 'key_one');

        $this->assertSame(409, $action['respond'][0]);
        $this->assertArrayNotHasKey('add', $action);
    }

    public function testCreditIsTheFiatAmountNeverTheCryptoAmount(): void
    {
        $action = $this->act(['status' => 'paid_over', 'amount' => 0.0009, 'fiat_amount' => 12.0, 'fiat_currency' => 'usd']);

        $this->assertSame('12', $action['add']['amount']);
    }

    public function testRepeatedDeliveryIsNotCreditedAgain(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], 'key_two', null, ['po0', 'po1']);

        $this->assertSame([200, 'Already processed'], $action['respond']);
        $this->assertArrayNotHasKey('add', $action);
    }

    // ─── keys ───────────────────────────────────────────────────────────────

    public function testNoMethodWithAKeyAnswers503(): void
    {
        $methods = [['id' => '1', 'secret_key' => '', 'topup_currency' => 'USD']];
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], '', $methods);

        $this->assertSame([503, 'Gateway not configured'], $action['respond']);
        $this->assertArrayNotHasKey('add', $action);
    }

    public function testNoMethodsAtAllAnswers503(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], 'key_two', []);

        $this->assertSame(503, $action['respond'][0]);
    }

    public function testKeylessMethodIsSkippedNotMatched(): void
    {
        // An empty key would "verify" a signature anyone can compute.
        $methods = [
            ['id' => '1', 'secret_key' => '', 'topup_currency' => 'USD'],
            ['id' => '2', 'secret_key' => 'key_two', 'topup_currency' => 'USD'],
        ];
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], '', $methods);

        $this->assertSame([401, 'Invalid signature'], $action['respond']);
        $this->assertArrayNotHasKey('add', $action);
    }

    public function testSignatureNoMethodMadeIs401(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], 'somebody_else');

        $this->assertSame([401, 'Invalid signature'], $action['respond']);
        $this->assertSame([], $this->lookups);
    }

    public function testPermanentAddressDepositIsAcknowledgedWithoutAction(): void
    {
        $action = $this->act(['status' => 'success', 'permanent_address_id' => 'pa1'], 'somebody_else');

        $this->assertSame([200, 'OK'], $action['respond']);
        $this->assertArrayNotHasKey('add', $action);
    }

    // ─── refusals ───────────────────────────────────────────────────────────

    /** @dataProvider unpaidStatuses */
    public function testUnpaidStatusCreditsNothing(string $status): void
    {
        $action = $this->act(['status' => $status, 'fiat_amount' => 40.0, 'fiat_currency' => 'USD']);

        $this->assertSame([200, 'OK'], $action['respond']);
        $this->assertArrayNotHasKey('add', $action);
        $this->assertSame([], $this->lookups);
    }

    public function unpaidStatuses(): array
    {
        return [['partially_paid'], ['cancelled'], ['failed'], ['']];
    }

    /** @dataProvider missingData */
    public function testMissingAccountOrderOrAmountIs400(array $payload): void
    {
        $body = json_encode($payload + ['status' => 'success', 'fiat_currency' => 'USD']);
        $action = PawPaymentsTopupAction($body, hash_hmac('sha256', $body, 'key_two'), $this->methods(), function () {
            $this->fail('the already-credited lookup must not run for a malformed webhook');
        });

        $this->assertSame([400, 'Missing data'], $action['respond']);
    }

    public function missingData(): array
    {
        return [
            'no account' => [['order_id' => 'po1', 'fiat_amount' => 40.0]],
            'account not a number' => [['extra' => 'x77', 'order_id' => 'po1', 'fiat_amount' => 40.0]],
            'no order_id' => [['extra' => '77', 'fiat_amount' => 40.0]],
            'no fiat_amount (crypto only)' => [['extra' => '77', 'order_id' => 'po1', 'amount' => 0.001]],
            'zero fiat_amount' => [['extra' => '77', 'order_id' => 'po1', 'fiat_amount' => 0]],
        ];
    }

    public function testTopupCurrencyNotSetWaitsWith503(): void
    {
        // 503, not a refusal: the gateway retries once the admin sets it.
        $methods = [['id' => '2', 'secret_key' => 'key_two', 'topup_currency' => '']];
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], 'key_two', $methods);

        $this->assertSame([503, 'Top-up currency not configured'], $action['respond']);
        $this->assertArrayNotHasKey('add', $action);
    }

    public function testDepositInAnotherCurrencyIsRefusedForGood(): void
    {
        // payment.add credits a bare number: 40 EUR must never become 40 USD.
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'EUR']);

        $this->assertSame([409, 'Currency does not match the top-up currency'], $action['respond']);
        $this->assertArrayNotHasKey('add', $action);
        $this->assertSame([], $this->lookups);
    }
}
