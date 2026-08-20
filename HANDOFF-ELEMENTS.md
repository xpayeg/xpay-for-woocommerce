# Handoff — should XPay for WooCommerce move to Elements?

Paste this whole file as the opening prompt of a new session. It is
self-contained: it states the question, what was already verified, and where
the evidence lives. Every claim below was read out of source in
`xpayeg/xpay` at the paths given, not recalled.

Read `HANDOFF.md` first for repo, branch and gate discipline. This file only
covers the Elements question.

---

## The ask

Decide whether the plugin should keep opening XPay's hosted checkout in a
drop-in window, or move to **Elements** (`uiMode: "custom"`), where XPay's
payment fields render inline on the WooCommerce page and the store's own
button submits them.

This is a product decision with real costs on both sides. Do not start
building. Produce a recommendation with the costs priced, then wait for
ma@xpay.app to choose.

## Where the plugin stands today

`includes/gateway/class-xpay-checkout-service.php:236` creates every session
with:

```php
'uiMode'          => 'hosted',
'afterCompletion' => array( 'type' => 'redirect', 'redirect' => array( 'url' => $return_url ) ),
'cancelUrl'       => $order->get_checkout_payment_url(),
```

and `assets/js/checkout-modal.js:172` then opens that hosted session in a
drop-in window:

```js
modal = xpay.checkout( { clientSecret: ..., mode: 'modal', ... } );
```

**There is a mismatch here worth resolving regardless of the Elements
decision.** The dashboard derives "how the customer paid" from the session's
`uiMode` and nothing else — see the comment at
`apps/nextjs/src/app/[locale]/(main)/[merchantId]/transactions/[transactionId]/components/IntegrationSource.tsx:37-45`,
which calls `uiMode` "the canonical signal". The mapping at `:20-35` is
`hosted` → "Hosted", `embedded` → "Drop-in", `custom` → "Embedded (Elements)".

So every WooCommerce transaction is currently labelled **Hosted** in the
merchant's dashboard, including the ones actually paid inside the drop-in
window. Switching that one field to `embedded` would tell the truth. It is
not free: see the `cancelUrl` cost below, which applies to `embedded` and
`custom` alike.

## What Elements actually is at XPay

Three UI modes exist (`apps/api/src/checkout/entities/checkout-session-ui-mode.enum.ts`):

| `uiMode` | What it is | Dashboard label |
|---|---|---|
| `hosted` | Redirect to XPay's checkout page | Hosted |
| `embedded` | XPay's checkout page inside an SDK window on your site | Drop-in |
| `custom` | XPay's Payment Element mounted into your own page | Embedded (Elements) |

A trap to avoid: **the SDK's `mode: 'inline'` is not Elements.** `'modal'`
and `'inline'` both load the identical hosted checkout URL
(`/c/{sessionId}?embed=true`); `'inline'` just puts that same full two-column
page in a container instead of an overlay. Elements is a different thing
entirely and needs `uiMode: "custom"` on the session.

The Elements surface is `packages/sdk/runtime/src/elements.ts` (787 lines) and
`packages/sdk/runtime/src/confirm-payment.ts` (235 lines). The shape, from
`apps/docs/content/docs/sdk/sdk-js.mdx:64-110`:

```js
const checkout = await xpay.initCheckout( { clientSecret } );
const elements = checkout.getElements();
elements.create( 'payment' ).mount( '#payment-element' );
const result = await checkout.confirm( { customerDetails: { email, name, phone } } );
```

`confirm()` takes `redirect: "if_required" | "always"` (default
`"if_required"`), which returns the result to your code and only navigates
when the method genuinely needs it — 3-D Secure, valU, Fawry. The SDK opens
its own action overlay for those.

## The hard constraints — these are enforced, not advisory

`apps/api/src/checkout/checkout.service.ts:178-196` rejects the session
outright if `uiMode: "custom"` is sent with any of:

- `nameCollection`
- `phoneNumberCollection`
- `billingAddressCollection`
- `shippingAddressCollection`
- `submitType`

The error is explicit: *"In custom mode, the merchant handles customer data
collection in their own UI."*

Two more that bite:

1. **`cancelUrl` is banned for both `embedded` and `custom`**
   (`checkout.service.ts:138-143`). The plugin sends one today. Whatever
   replaces it has to be built in WooCommerce.
2. **`CUSTOM`-type prices are rejected under `uiMode: "custom"`**
   (`apps/api/src/checkout/services/checkout-line-item.service.ts:126-131`).
   The plugin always sends a fixed `unitAmount`, so this does not bite us —
   but it forecloses any future "shopper names their own amount" feature on
   the Elements path.

`afterCompletion.type` must be `redirect` for both non-hosted modes
(`checkout.service.ts:158-174`). The plugin already satisfies this.

## What Elements buys

- **No window opens over the checkout.** The payment fields are part of the
  page. This is the whole reason to consider it.
- **It dodges the unclosable-window bug.** Platform issue #1 (a drop-in window
  that refuses to close after a failed attempt) cannot happen when there is no
  window. The plugin currently works around that bug in JS; Elements removes
  the surface.
- **Theming is a real API, not a wish.** `Appearance`
  (`packages/sdk/js/src/types.ts:63-66`, documented at `sdk-js.mdx:434-450`)
  carries `colorMode: "system" | "light" | "dark"`, `borderStyle`, `spacing`,
  `inputSize`, `inputStyle`, `formLayout`, twelve semantic `colors` and
  `fontFamily`. It can be passed at creation or changed at runtime with
  `elements.changeAppearance()`. This is the honest answer to the
  light/dark-theme question asked earlier in the project.

## What Elements costs

- **The store inherits every customer field.** Name, phone, billing address,
  shipping address — the five blocked options above are blocked because XPay
  stops collecting them. The plugin must gather them from WooCommerce and pass
  them to `confirm()` as `customerDetails`. Prefilling is the easy half;
  owning the validation is the hard half.
  The valU mobile number is the concrete example: today the payment window
  asks for it and validates it. Under Elements that becomes the plugin's
  field, its prefill, its error message.
- **`cancelUrl` has to be rebuilt** as WooCommerce behaviour.
- **`PaymentElementOptions` is declared but unwired.** `docs/sdk/SDK_ISSUES.md`
  flags it as "No-op — must be implemented or removed before merchant beta",
  and the source agrees: `elements.ts:148` takes `_options` and discards it,
  `elements.ts:681` makes `update()` a no-op. So `layout`,
  `defaultPaymentMethod` and `paymentMethodOrder` do nothing today. Do not
  design around them.
- **`CardElement` no longer exists.** It was removed in SDK v1.0.1, which is
  why `docs/sdk/SDK_WOOCOMMERCE.md:45` marks the per-method-gateway plan
  ("Option 3a") as an open question needing a new approach. A card-only form
  is not currently buildable; the Payment Element renders the whole method
  accordion or nothing.

## Prior art in the monorepo

`docs/sdk/SDK_WOOCOMMERCE.md` is the platform team's own plan for this plugin
and already frames four options: redirect, drop-in modal, per-method native
gateways (3a), and one gateway hosting the full Payment Element (3b). We
shipped the redirect and the drop-in. **Elements as described here is their
Option 3b.** Read that file before proposing anything, and note where it has
gone stale — the `CardElement` removal invalidates 3a as written.

`apps/example-store/src/lib/checkout.ts:6` is a working `uiMode: "custom"`
integration to read.

## Mockups already produced

Three artboards showing the Elements route on both pages, built with real XPay
tokens (Inter, `--primary: oklch(0.51 0.23 277)`, 10px radius):

1. **Checkout page** — the method accordion inline in the WooCommerce form,
   one "Pay Now" button, no overlay
2. **Order-pay page** — the branded receipt keeps the stage, payment fields sit
   inside it
3. **valU selected** — shows the mobile-number field the store would have to
   own

Working files: `scratchpad/elements-design/` (`Main.dc.html`,
`OrderPay.dc.html`, `ValuSelected.dc.html`, `canvas.json`). Re-seed and
republish rather than rebuilding from scratch.

## Open questions for the founder

1. Is removing the window worth taking on customer-data collection and its
   validation?
2. Elements everywhere, or only on the checkout page with the pay page left as
   it is?
3. What replaces `cancelUrl` — a WooCommerce-side "back to cart" affordance, or
   nothing?
4. Independent of the above: switch `uiMode` to `embedded` now so the dashboard
   stops calling drop-in payments "Hosted"? This costs the `cancelUrl` too.

## First moves for whoever picks this up

Do not write gateway code yet.

1. Re-read the six source locations cited above and confirm nothing has moved.
2. Price question 4 on its own — it is small, independent, and shippable
   before any Elements decision.
3. Build a throwaway `uiMode: "custom"` session against the test store and
   mount a Payment Element, to see what the accordion actually looks like at
   WooCommerce widths. Do not merge it.
4. Bring back a recommendation with the customer-data work itemised.
