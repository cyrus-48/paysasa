# Quick Start

## STK Push (M-Pesa)

```php
use Paysasa\Payments\Facades\Payment;

$response = Payment::driver('mpesa')
    ->amount(2500)
    ->currency('KES')
    ->phone('254712345678')
    ->customer($user)
    ->reference('INV1001')
    ->description('School Fees')
    ->metadata(['student_id' => 10, 'invoice' => 455])
    ->charge();

// $response->pending() === true here — the customer hasn't entered their PIN yet.
// Listen for Events\PaymentSuccessful / Events\PaymentFailed to know the outcome.
```

## Card charge (Stripe, synchronous)

```php
$response = Payment::driver('stripe')
    ->amount(49.99)
    ->currency('USD')
    ->cardToken($stripePaymentMethodId) // from Stripe.js on the frontend — never handle raw card numbers server-side
    ->reference('ORDER-778')
    ->charge();

if ($response->successful()) {
    // settled synchronously
}
```

## Hosted checkout (Pesapal / Flutterwave / Paystack)

```php
$response = Payment::driver('paystack')
    ->amount(1000)
    ->phone('254712345678')
    ->reference('ORDER-779')
    ->callbackUrl(route('checkout.callback'))
    ->charge();

return redirect($response->rawResponse()['metadata']['authorization_url']
    ?? $response->toArray()['metadata']['authorization_url']); // redirect the customer to the hosted page
```

## Payouts (B2C / bank transfer)

```php
Payment::driver('mpesa')
    ->amount(15000)
    ->phone('254712345678')
    ->reference('PAYROLL-JAN-001')
    ->description('January salary')
    ->payout();

Payment::driver('pesalink')
    ->amount(50000)
    ->reference('SUPPLIER-PAY-42')
    ->metadata(['account_number' => '0123456789', 'bank_code' => '01'])
    ->payout();
```

## Refunds

```php
Payment::driver('stripe')->refund(
    transactionId: $payment->uuid,
    providerReference: $payment->provider_reference,
    reason: 'requested_by_customer',
);
```

## Verifying a still-pending payment

Normally you don't call this yourself — `VerifyPaymentStatusJob` does it automatically as a webhook safety net — but it's available directly:

```php
Payment::driver('mpesa')->verify($checkoutRequestId);
```

## Reacting to outcomes

```php
// In a listener, or inline via Event::listen() in your EventServiceProvider
use Paysasa\Payments\Events\PaymentSuccessful;

Event::listen(PaymentSuccessful::class, function (PaymentSuccessful $event) {
    $event->payment->update(['invoice_status' => 'paid']);
    Mail::to($event->payment->customer_email)->send(new ReceiptMail($event->payment));
});
```

See [`04-architecture.md#event-lifecycle`](04-architecture.md#event-lifecycle) for the full list of 11 events and when each fires.

## Multi-merchant

```php
Payment::forMerchant($tenant->id)->driver('mpesa')->amount(500)->phone($phone)->charge();
```

Resolves credentials from that merchant's `provider_accounts` row instead of the global config — see [`04-architecture.md`](04-architecture.md).
