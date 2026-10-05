# smsrouting/laravel

Country-aware SMS routing for Laravel 8–11: pick a gateway chain per recipient country, fail over to the next provider automatically, and send every notification through **one** notification channel instead of a dozen hand-written channels.

```
+79151234567  →  country=RU  →  SMS_ROUTE_RU  →  sigmasms_ru  →  (failed)  →  smsc  →  sent
```

- **Routing by country** — the ISO2 country is resolved from the phone number with `giggsey/libphonenumber-for-php`, so a single notification works for every market you operate in.
- **Failover inside a chain** — `"SMS_ROUTE_RU=sigmasms_ru,smsc"` means "try SigmaSMS first, fall back to SMSC on failure". Order is priority.
- **One channel** — `SmsRouting\Channels\SmsChannel` replaces the per-provider channels. It never talks to a provider API directly, it delegates to `SmsManager`.
- **Nine providers out of the box** — SMSC.RU, SigmaSMS, SMS.to, SMSFly, Karix, EasySendSMS, Botto, Laaffic, GreenSMS.
- **Multiple accounts per provider** — two regional SigmaSMS contracts with separate API keys and sender names, referenced in routes by instance name.
- **Per-gateway OTP templates** — the text is rendered by the gateway that *actually* sent the message, so a failover never sends provider A's wording through provider B.
- **Rate limiting is yours** — the package defines the `SmsRateLimiter` contract and calls it; your app decides who "the same recipient" is (a DB column, Redis, cache).
- **Delivery events** — every successful send dispatches `SmsRouting\Events\SmsSent` with the result, so your app can write its own journal/audit log.

## Requirements

| | |
|---|---|
| PHP | 8.1+ |
| Laravel | 8, 9, 10, 11 (illuminate 8–11) |
| Phone parsing | `giggsey/libphonenumber-for-php` ^8.12 (installed automatically) |

Provider SDKs are **optional** — see [Optional provider SDKs](#optional-provider-sdks).

## Installation

```bash
composer require smsrouting/laravel
```

The service provider (`SmsRouting\SmsRoutingServiceProvider`) is auto-discovered. It merges the package config, binds `SmsManager`, registers the package log channels, and does nothing else.

Publish the config if you want to change the routing table in your repo:

```bash
php artisan vendor:publish --tag=sms-config
```

> Publish the config **before** you start tuning routes in `.env` only. A published `config/sms.php` in the app replaces the package defaults for the keys you keep — see [Config merge rules](#config-merge-rules).

Local development against a checkout:

```json
// composer.json of your app
{
    "repositories": [
        { "type": "path", "url": "../sms-routing", "options": { "symlink": true } }
    ],
    "require": {
        "smsrouting/laravel": "^1.0"
    }
}
```

```bash
composer update smsrouting/laravel
```

## Credentials

Routing and credentials are deliberately separate. **Keys live in `config/services.php`, routing lives in `config/sms.php` / `.env`.**

```php
// config/services.php
'sigmasms' => [
    'token' => env('SIGMASMS_TOKEN'),
],
'karix' => [
    'api_key' => env('KARIX_API_KEY'),
],
'smscru' => [
    'login' => env('SMSC_LOGIN'),
    'password' => env('SMSC_PASSWORD'),   // may be an API key
],
'laaffic' => [
    'api_key' => env('LAAFFIC_API_KEY'),
    'app_id' => env('LAAFFIC_APP_ID'),
    'app_secret' => env('LAAFFIC_APP_SECRET'),
],
'botto' => ['password' => env('SMSBOTTO_PASSWORD')],
'easysendsms' => ['api_key' => env('EASYSENDSMS_API_KEY')],
'greensms' => ['token' => env('GREENSMS_TOKEN')],
'smsto' => ['api_key' => env('SMSTO_API_KEY')],
// SMSFly ships its own config file: config/gateway-smsfly.php (nekkoy/gateway-smsfly)
```

A gateway is **available** when it is `enabled` in `config/sms.php` *and* its API key resolves to a non-empty string. Unavailable gateways are skipped silently during dispatch (the reason is collected and thrown with the final exception, and written to the log).

## How routing works

The country is resolved from the recipient number, then the first non-empty chain wins:

| # | Source | Config key | `.env` variable |
|---|---|---|---|
| 1 | Exact country | `sms.routes.{ISO2}` | `SMS_ROUTE_RU`, `SMS_ROUTE_KZ`, `SMS_ROUTE_BY`, … |
| 2 | First matching region group | `sms.groups.{group}.chain` | `SMS_ROUTE_CIS`, `SMS_ROUTE_EUROPE`, `SMS_ROUTE_LATAM` |
| 3 | Global default | `sms.default` | `SMS_ROUTE_DEFAULT` |
| 4 | Last resort, single provider | `sms.default_gateway` | `SMS_DEFAULT_GATEWAY` (fallback `karix`) |

**An empty or unset variable means "no route here", and the search continues.** This is the single most important rule of the package: no fallback chain is ever hardcoded into the config, because a hardcoded fallback silently overrides your `.env`. (Earlier versions of this config shipped `'RU' => env('SMS_ROUTE_RU', 'sigmasms_ru,smsc')`, and every Russian SMS went through SigmaSMS/SMSC even when `SMS_ROUTE_DEFAULT` said otherwise — that class of bug is why the rule exists.)

Verified behaviour with **only** `SMS_ROUTE_DEFAULT=karix,smsc` set:

| Recipient country | Chain used |
|---|---|
| `RU` (has a `routes` key, member of `cis`) | `karix,smsc` — the default |
| `KZ`, `BY` (members of the `cis` group) | `karix,smsc` — the default |
| `DE` (member of `europe`) | `karix,smsc` — the default |
| `BR` (member of `latam`) | `karix,smsc` — the default |
| `US` (no route, no group) | `karix,smsc` — the default |
| unparsable number | `karix,smsc` — the default |

Add `SMS_ROUTE_RU=sigmasms_ru` and RU immediately switches, everything else keeps the default. Add `SMS_ROUTE_CIS=sigmasms_ru` and RU + the other CIS members switch as a block.

### Chains are lists of instances

A chain value is a comma-separated list of **instance names**, and the order is the failover order:

```dotenv
SMS_ROUTE_RU=sigmasms_ru,smsc    # regional SigmaSMS account first, SMSC if it fails
SMS_ROUTE_KZ=sigmasms_kz,smsc    # regional SigmaSMS account first
```

Every provider automatically has an instance named after its code (`smsc`, `sigmasms`, `karix`, …), so routes written before multi-account support keep working unchanged. Names are case-insensitive and de-duplicated.

Unknown names are **kept** in the chain and skipped at dispatch time with a log line — a typo in `.env` must not silently shorten the chain and send the SMS from an account you did not intend.

## Configuring failover per country

### 1. One chain for everyone

```dotenv
SMS_ROUTE_DEFAULT=karix,smsc
```

### 2. A dedicated chain per country

```dotenv
SMS_ROUTE_DEFAULT=karix,smsc
SMS_ROUTE_RU=sigmasms_ru,smsc
SMS_ROUTE_KZ=sigmasms_kz,smsc
SMS_ROUTE_BR=sigmasms,smsc
```

Countries without their own route fall back to `SMS_ROUTE_DEFAULT`. To add a country, add a key to `config('sms.routes')` and the matching `SMS_ROUTE_XX` variable.

### 3. A chain per region group

```dotenv
SMS_ROUTE_DEFAULT=karix,smsc
SMS_ROUTE_CIS=sigmasms_ru,smsc
SMS_ROUTE_EUROPE=sigmasms,smsc
SMS_ROUTE_LATAM=sigmasms,smsc
```

Group membership is an explicit list (`countries`), not a first-letter prefix, so a group can never silently capture countries you did not mean. Country routes still win over groups.

### 4. Separate accounts per country

```php
// config/sms.php
'instances' => [
    'sigmasms_ru' => [
        'provider' => 'sigmasms',
        'credentials' => ['token' => env('SIGMASMS_RU_TOKEN')],
        'sender' => env('SIGMASMS_RU_SENDER'),
    ],
    'sigmasms_kz' => [
        'provider' => 'sigmasms',
        'credentials' => ['token' => env('SIGMASMS_KZ_TOKEN')],
        'sender' => env('SIGMASMS_KZ_SENDER'),
    ],
],
```

```dotenv
SMS_ROUTE_RU=sigmasms_ru,smsc
SMS_ROUTE_KZ=sigmasms_kz,karix
```

Supported per-instance keys: `provider` (required), `credentials`, `sender`, `otp_template`, `enabled`, `log_channel`.

- An **empty** `credentials` value means "use the provider-wide key" — an unset `.env` variable must not break sending.
- An instance name must differ from every provider code (`smsc`, `sigmasms`, …), otherwise you get an `InvalidArgumentException`.
- `smsc` and `smsto` read their keys internally and **cannot** use per-instance credentials. Declaring `credentials` for them throws at boot, on purpose: a config that looks valid but sends from the wrong account is worse than a boot failure. Those providers can still have several instances for `sender` / `otp_template` / `log_channel` only.

### 5. What failover does *not* do

If a route resolves to a chain and **every** gateway in it is unavailable or failing, the default chain is **not** substituted — `SmsManager` throws `SmsException` with the per-gateway reasons attached:

```php
catch (SmsException $e) {
    $e->failures();
    // ['smsc: not available (disabled or no credentials)', 'karix: HTTP 401 ...']
    $e->gateways(); // [SmsGateway::SMSC, SmsGateway::KARIX]
}
```

This is intentional: a silent jump to another country/provider usually means balances, sender names, or content rules are violated somewhere else. If you want the default chain as a safety net, put it in the chain explicitly:

```dotenv
SMS_ROUTE_RU=sigmasms_ru,smsc,karix
```

### 6. Turning failover off

```dotenv
SMS_FAILOVER_ENABLED=false
```

The first failure (both a thrown error and a soft failure in the response body) stops the chain. Useful when a message must never be sent twice.

### 7. OTP templates per provider

```dotenv
SMSC_OTP_TEMPLATE="Код подтверждения:%code"
SIGMASMS_OTP_TEMPLATE="ваш код: %code"
GREENSMS_OTP_TEMPLATE="Ваш код подтверждения: %code"
SMSFLY_OTP_TEMPLATE="%code"
```

`%code` is the single placeholder, substituted by the gateway that actually delivers the message. Templates without `%code` are sent as-is and produce a warning in the log.

### 8. Sender names and provider switches

```dotenv
KARIX_SENDER=MyBrand
SIGMASMS_SENDER=MyBrand
SMS_KARIX_ENABLED=false        # hard-disable a provider
SMS_FAILOVER_ENABLED=true
SMS_TIMEOUT=8
SMS_RATE_LIMIT_MINUTES=1
SMS_LOG_CHANNEL=sms
```

## Notifications

Implement `HasSmsPayload` and route through `SmsChannel`:

```php
use Illuminate\Notifications\Notification;
use SmsRouting\Channels\SmsChannel;
use SmsRouting\Contracts\HasSmsPayload;

class VerifyPhone extends Notification implements HasSmsPayload
{
    public function __construct(private string $code) {}

    public function via($notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSmsPayload(mixed $notifiable): array
    {
        return [
            'phone' => $notifiable->phone,
            'otp_code' => $this->code,   // text is rendered by the gateway
        ];
    }
}
```

- `phone` — recipient number, any format `PhoneCountryResolver` can normalise.
- `text` — ready text for a plain SMS.
- `otp_code` — send an OTP; the channel calls `SmsManager::sendOtp()` so the per-gateway template applies.

You can also skip notifications entirely:

```php
app(SmsRouting\SmsManager::class)->send('+79151234567', 'Hello');
app(SmsRouting\SmsManager::class)->sendOtp('+79151234567', '1234');

// Which chain would this number use?
app(SmsRouting\SmsManager::class)->chainFor('RU'); // ['sigmasms_ru', 'smsc']
```

### Rate limiting

The package asks "is this the same recipient as before?" through a contract, and never touches your models:

```php
use SmsRouting\Contracts\SmsRateLimiter;

class UserSmsRateLimiter implements SmsRateLimiter
{
    public function __construct(private Users $users) {}

    public function isThrottled(mixed $notifiable, int $minutes): bool
    {
        $last = $this->users->find($notifiable->getKey())?->sms_sent_at;

        return $last !== null && Carbon::parse($last)->diffInMinutes(now()) < $minutes;
    }

    public function record(mixed $notifiable): void
    {
        $this->users->find($notifiable->getKey())->update(['sms_sent_at' => now()]);
    }

    public function lastSentAt(mixed $notifiable): ?string
    {
        return $this->users->find($notifiable->getKey())?->sms_sent_at;
    }
}
```

```php
// AppServiceProvider::register()
$this->app->singleton(SmsRateLimiter::class, fn() => new UserSmsRateLimiter());
```

or point the config at it: `SMS_RATE_LIMITER="App\Services\Sms\UserSmsRateLimiter"`. Without a binding the package uses `NullSmsRateLimiter` (no throttling). Throttled sends return a `SmsResultDTO` with `skipped` status instead of throwing, and log `SMS skipped by rate limit`.

> Your implementation must receive the **same object** the channel passes in, otherwise `record()` will not find the subject.

### Delivery events

```php
use SmsRouting\Events\SmsSent;

Event::listen(SmsSent::class, function (SmsSent $event) {
    $event->notifiable;   // mixed, exactly what you passed to the channel
    $event->result;       // SmsResultDTO (success, gateway, instance, messageId, cost, raw)
});
```

## Optional provider SDKs

Three adapters wrap third-party SDKs. They are `suggest`ed, not required; without the SDK the adapter is skipped at boot with a `SMS gateway skipped: SDK is not installed` warning and simply never appears in any chain.

| Adapter | Package | Credentials read from |
|---|---|---|
| `SmsToGateway` | `intergo/sms.to-lumen` | `config/smsto.php` |
| `SmsFlyGateway` | `nekkoy/gateway-smsfly` | `config/gateway-smsfly.php` |
| `GreenSmsGateway` | `greensms/greensms` | `config/services.php` → `services.greensms.token` |

The other six (SMSC, SigmaSMS, Karix, EasySendSMS, Botto, Laaffic) use plain HTTP/curl and need nothing beyond this package.

## Log channels

The provider registers a `daily` log channel per provider (`sms`, `smsc`, `sigmasms`, `smsto`, `smsfly`, `karix`, `easysendsms`, `smsbotto`, `laaffic`, `greensms`) **only if your app does not define them**, so your own `config/logging.php` always wins. Useful lines:

```
SMS dispatch            {"phone":..., "country":"RU", "chain":"sigmasms_ru,smsc", "kind":"otp"}
SMS gateway failed      {"gateway":"karix", "instance":"karix", "error":"HTTP 401 ..."}
SMS sent                {"gateway":"smsc", "instance":"smsc", "phone":..., "message_id":123}
SMS dispatch failed on all gateways   {"failures":{...}}
```

Sender names, message IDs and cost are logged, message bodies are not.

## Writing your own gateway

```php
namespace App\Sms\Gateways;

use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Enum\SmsGateway;
use SmsRouting\Gateways\AbstractSmsGateway;

class MyGateway extends AbstractSmsGateway
{
    public static function sdkClass(): ?string   // null if only core deps are needed
    {
        return null;
    }

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::KARIX;                 // or add a case to the enum
    }

    protected function credentialPaths(): array
    {
        return ['api_key' => 'services.mysms.api_key'];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('api_key');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        $response = Http::timeout($this->timeout())
            ->post('https://api.mysms.example/send', [
                'key' => $this->apiKey(),
                'to' => $phone,
                'text' => $text,
            ]);

        return $response->successful()
            ? SmsResultDTO::success(
                gateway: $this->codeEnum(),
                phone: $phone,
                messageId: (string) $response->json('id'),
                instance: $this->instance(),
            )
            : SmsResultDTO::failure($this->codeEnum(), 'HTTP ' . $response->status(), $phone, $response->json());
    }
}
```

Register it in `SmsManager::gatewayClasses()` (or pass your own class list) and add its `enabled`/`sender`/`otp_template` block to `config/sms.php`. The abstract class gives you `isAvailable()`, `sender()`, `otpTemplate()`, `withInstance()`, per-instance credential resolution, `logSuccess()` / `logFailure()`, and the timeout.

## Config merge rules

The provider calls `mergeConfigFrom()`, which merges **top-level keys only**:

- If you publish `config/sms.php`, your `routes` array **replaces** the package one entirely — list every country you need there.
- Keys you delete from your published file come back from the package defaults.
- `env()` is read when the config is built, so after editing `.env` run `php artisan config:clear` (or `config:cache`) — a cached config is the single most common reason for "I set the variable and nothing changed".

## Troubleshooting

**`SMS_ROUTE_DEFAULT` is ignored for some countries.** Check `php artisan tinker` → `config('sms.groups')`: if a group has a non-empty `chain` for that country, the group wins. Groups have no hardcoded defaults, so a value there always comes from `.env`.

**The chain is right but the SMS still goes through the wrong provider.** `config('sms.instances')` may register an instance whose name collides with what you expect. Dump the effective chain: `app(SmsRouting\SmsManager::class)->chainFor('RU')`.

**`InvalidArgumentException: instance "..." collides with an already registered instance`.** An instance name equals a provider code. Rename it.

**`InvalidArgumentException: provider "smsc" reads its credentials internally`.** Remove `credentials` from that instance and keep the key in `config/services.php`.

**Nothing happens, no exception, `skipped`.** Rate limit. Check `SMS_RATE_LIMIT_MINUTES` and the `SMS skipped by rate limit` log line.

**`SMS gateway skipped: SDK is not installed`.** Install the optional SDK from the table above or remove the adapter from `gatewayClasses()`.

**Sender name rejected by the provider.** Sender names must be pre-registered with the provider; set `*_SENDER` to the exact approved name.

## Migrating from hand-written channels

Typical shape before:

```php
class SmsChannel { /* ... */ }        // SMSC
class SigmaSmsChannel { /* ... */ }    // SigmaSMS
class KarixChannel { /* ... */ }        // Karix
// ... one class per provider, each with its own OTP wording,
//     its own rate-limit check and its own error handling
```

After: delete them, keep one notification, describe the routing in `.env`. The failure modes that duplicated code per channel (different OTP wording, inconsistent throttling, silent double sends) are handled once, in `SmsManager`.

## Testing

```bash
composer install
composer test
```

The suite runs on plain PHPUnit and needs no `laravel/framework` and no `orchestra/testbench`: the package itself only uses `illuminate/container`, `config`, `log`, `events`, `http` and `notifications`, and the tests boot exactly that minimum. That keeps `composer install` for development fast and avoids pulling a whole application skeleton into a library test run. (`phpoption/phpoption` and `vlucas/phpdotenv` are dev-only: `env()` inside `config/sms.php` needs them, and in a real application they arrive with the framework.)

Every gateway is replaced by a fake, so the suite never touches the network and never needs real credentials. Routing, failover, instances, the rate-limit contract and the notification channel are all covered:

| Suite | What it pins down |
|---|---|
| `RoutingChainTest` | country → group → default priority, and the guarantee that no route or group carries a hardcoded chain |
| `FailoverTest` | soft failure, exception, missing credentials, `SMS_FAILOVER_ENABLED=false`, OTP text built by the gateway that actually sent |
| `GatewayInstanceTest` | multi-account instances, per-instance overrides, name collisions, providers that cannot take per-instance credentials |
| `RateLimitTest` | the `SmsRateLimiter` contract, skipped results, cooldowns not started by failed attempts |
| `SmsChannelTest` | `HasSmsPayload`, the `SmsSent` event, OTP vs plain text |
| `PhoneCountryResolverTest` | country detection by number prefix, non-geographic codes |
| `SmsResultAndExceptionTest` | `SmsResultDTO` factories and `SmsException::failures()` |

## Contributing

Issues and pull requests are welcome. Please include the `.env` keys you used (values redacted), the recipient's country and the relevant log lines — routing bugs are almost always reproducible from `SMS dispatch` + `SMS gateway failed`.

## License

MIT.
