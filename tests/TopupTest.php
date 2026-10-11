<?php

use PHPUnit\Framework\TestCase;

/**
 * The legacy top-up webhook (pawpaymentstopup_result.php): which method's key
 * verifies it, and that a paid invoice is logged for a manual credit, never
 * credited by the module. Two PawPayments methods unless a test says otherwise.
 */
class TopupTest extends TestCase
{
    private function methods(): array
    {
        return [
            ['id' => '1', 'secret_key' => 'key_one'],
            ['id' => '2', 'secret_key' => 'key_two'],
        ];
    }

    private function act(array $payload, string $key = 'key_two', ?array $methods = null): array
    {
        $body = json_encode($payload + ['extra' => '77', 'order_id' => 'po1']);

        return PawPaymentsTopupAction($body, hash_hmac('sha256', $body, $key), $methods ?? $this->methods());
    }

    private function errors(array $action): array
    {
        return array_values(array_map(
            fn($log) => $log[1],
            array_filter($action['logs'], fn($log) => $log[0] === 'error')
        ));
    }

    /** @dataProvider paidStatuses */
    public function testPaidInvoiceIsLoggedForAManualCreditAndNotCredited(string $status): void
    {
        $action = $this->act(['status' => $status, 'fiat_amount' => 40.5, 'fiat_currency' => 'USD']);

        $this->assertSame([200, 'OK'], $action['respond']);
        $this->assertSame(['respond', 'logs'], array_keys($action));
        $errors = $this->errors($action);
        $this->assertCount(1, $errors);
        foreach (['po1', '40.5 USD', 'client 77', 'method 2', 'credit it manually'] as $needle) {
            $this->assertStringContainsString($needle, $errors[0]);
        }
    }

    public function paidStatuses(): array
    {
        return [['success'], ['paid_over']];
    }

    public function testLogShowsTheFiatAmountNeverTheCryptoAmount(): void
    {
        $action = $this->act(['status' => 'paid_over', 'amount' => 0.0009, 'fiat_amount' => 12.0, 'fiat_currency' => 'usd']);

        $this->assertStringContainsString('12 USD', $this->errors($action)[0]);
        $this->assertStringNotContainsString('0.0009', $this->errors($action)[0]);
    }

    /** @dataProvider unpaidStatuses */
    public function testUnpaidStatusIsOnlyDebugLogged(string $status): void
    {
        $action = $this->act(['status' => $status, 'fiat_amount' => 40.0, 'fiat_currency' => 'USD']);

        $this->assertSame([200, 'OK'], $action['respond']);
        $this->assertSame([], $this->errors($action));
    }

    public function unpaidStatuses(): array
    {
        return [['partially_paid'], ['cancelled'], ['failed'], ['']];
    }

    // ─── keys ───────────────────────────────────────────────────────────────

    public function testNoMethodWithAKeyAnswers503(): void
    {
        $methods = [['id' => '1', 'secret_key' => '']];
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], '', $methods);

        $this->assertSame([503, 'Gateway not configured'], $action['respond']);
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
            ['id' => '1', 'secret_key' => ''],
            ['id' => '2', 'secret_key' => 'key_two'],
        ];
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], '', $methods);

        $this->assertSame([401, 'Invalid signature'], $action['respond']);
    }

    public function testSignatureNoMethodMadeIs401(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], 'somebody_else');

        $this->assertSame([401, 'Invalid signature'], $action['respond']);
        $this->assertStringNotContainsString('credit it manually', implode("\n", $this->errors($action)));
    }

    public function testTheVerifyingMethodIsNamedInTheLog(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], 'key_one');

        $this->assertStringContainsString('method 1', $this->errors($action)[0]);
    }

    public function testPermanentAddressDepositIsAcknowledgedWithoutAction(): void
    {
        $action = $this->act(['status' => 'success', 'permanent_address_id' => 'pa1'], 'somebody_else');

        $this->assertSame([200, 'OK'], $action['respond']);
        $this->assertSame([], $action['logs']);
    }
}
