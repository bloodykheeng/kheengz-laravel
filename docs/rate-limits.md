# Rate limits

How hard each caller may use the API. Code: `App\Support\BuyerRateLimits` (registered in
`AppServiceProvider`), copied from GiftNest's guest limits and renamed for buyers.

## Why per person, not per IP

Most buyers are on MTN or Airtel, which put many handsets behind one public address (carrier-grade
NAT); a market's or an office's Wi-Fi puts everyone behind one. A limit per IP treats them all as one
person, and a flash sale is exactly when it would trip, for everybody at once. So each limit is per
**person** where the request says who it is, and per IP only as a high ceiling against one machine
flooding the API.

"Who" is, in order: a phone number typed (`phone`, `payment_phone`), else an email typed, else the
order's `{reference}` in the route, else a code's `{token}`, else the IP.

## The limiters

| Name | For | Per person / reference | IP ceiling (per minute) |
| --- | --- | --- | --- |
| `shop-reads` | shop pages, products, search | none | 6,000 |
| `order-follow` | one order or payment being watched (the screen polls while a prompt waits) | 120 a minute per reference | 6,000 |
| `buyer-writes` | placing an order, paying, retrying, confirming a code | 10 a minute per person | 600 |
| `otp-codes` | sending a code by SMS (tracking an order, saving details): each costs an SMS | 3 a minute per number | 120 |

Signed-in routes: `throttle:240,1` per account; sign-in and reset: `throttle:20,1`; resending an email
code: `throttle:3,1`.

The storefront routes that use these arrive with the storefront and orders modules (Phase 1); each
route group names its limiter (`throttle:shop-reads`, ...). Refusals are one plain sentence, never a
stack of headers: "The shop is very busy right now..." for the IP ceiling, "That was a lot of tries..."
for one person.
