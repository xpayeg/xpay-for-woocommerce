# Customer details under Elements

What the plugin sends about the shopper, and when, once the payment fields
move onto the store's own checkout page. Read `HANDOFF-ELEMENTS.md` first for
the Elements decision this sits inside, and `HANDOFF.md` for repo and gate
discipline.

Every rule below was read out of source in `xpayeg/xpay` at commit `e355ca7`,
not recalled.

## Decisions taken

- Elements is going ahead (`uiMode: "custom"`).
- The plugin validates the phone on its own side. No platform change is
  waited on.
- The correction field is built on the checkout page, on **both** the
  classic and the Blocks checkout. The pay page keeps its current behaviour
  for now.
- valU can be charged only on an **Egyptian or Jordanian mobile**. Any other
  number, however real, names no wallet and the shopper is asked.
- What valU does with a well formed but wrong number is out of scope.

## One payload becomes two

Today everything about the shopper travels at session creation. Under
Elements it splits.

| Moment | Where | Carries |
|---|---|---|
| Session creation | PHP, server side, unchanged | who the customer is |
| Confirm | JavaScript, browser, new | what the payment needs |

That split is what rescues the repeat customer. The rule forbidding a
customer id next to customer details applies only at session creation.

## The rules, as enforced

At session creation (`apps/api/src/checkout/checkout.service.ts`):

- `customerId` together with `customerDetails` is rejected outright (`:106`).
- `customerId` together with `customerCreation: always` is rejected (`:124`).

At confirm, which lands on `/collect`
(`apps/api/src/checkout/services/checkout-session-collect.service.ts`):

- No mutual exclusion check exists. `customerDetails` is read off the session
  and passed to customer resolution whatever the session's `customerId` says.
- A **registered** customer is left untouched. It is merchant pinned, and
  only explicit `customerUpdate` flags overwrite it.
- A **guest** customer is recompounded, so name, phone and address track what
  the shopper last entered, and prior values land in the fingerprint arrays
  that later identifier matching reads.

The path from the browser was traced end to end and nothing drops the
payload: `confirm()` sends `XPAY_SDK_CUSTOMER_DETAILS`
(`packages/sdk/runtime/src/confirm-payment.ts:65`), the embed holds it in a
ref (`apps/checkout/src/checkout/hooks/use-embed-bridge.ts:150`), and passes
it alongside the submit (`:155`), which reaches `/collect`.

## What the plugin sends

| Shopper | At session creation | At confirm |
|---|---|---|
| Repeat, signed in, id stored | `customerId` alone | `phone` |
| First time, signed in | `customerDetails` + `customerCreation: always` | `phone` |
| Guest | `customerDetails` alone | `phone` |

Notes that are easy to get wrong later:

- Guests deliberately carry no `customerCreation`. The platform default finds
  or creates a guest record and dedupes it on email or phone. Forcing records
  would be noise in the merchant's customer list.
- Name and email are not resent for a repeat shopper. The record is pinned
  and would not be written anyway.
- The phone rides every attempt because it belongs to the payment, not to the
  record: valU charges the wallet the session's phone names.
- Guest matching needs an email or a phone. A name alone resolves to no
  customer at all. WooCommerce always carries an email, so this holds, but it
  is worth a test that pins it.

## Where the shopper types the phone

Today XPay's window shows the phone prefilled and editable, so a wrong number
can be corrected at the moment of paying. That window is what Elements
removes, so the affordance becomes ours.

On the checkout page WooCommerce already renders a billing phone field and
the shopper has already filled it. We read that field live rather than adding
a second one. Our own input appears only when valU is selected **and** that
phone is missing or fails our check, as a correction prompt attached to the
valU row.

The two checkouts reach that behaviour by different routes, because Blocks
renders its rows in the browser.

- **Classic**: `payment_fields()` renders the prompt and `validate_fields()`
  gates the submission. WooCommerce refreshes the payment box on country,
  state and postcode but never on the phone, so `checkout-wallet-phone.js`
  makes the phone field trigger that refresh and carries a typed value
  across it.
- **Blocks**: the rule stays in PHP and only its verdict crosses, published
  on the Store API cart response under the `xpay` extension namespace and
  recomputed whenever Blocks refetches the cart. `validate_fields()` is
  never called by the Store API, so the gate lives on
  `woocommerce_store_api_checkout_update_order_from_request` instead.

Two traps that cost a browser run each, recorded so they are not rediscovered:

1. That Store API hook fires for Blocks' **draft order sync** as well as for
   the real submission. Selecting the valU row makes Blocks POST the chosen
   method to the same endpoint, so an ungated gate refuses the order the
   instant the shopper picks valU, before the prompt has been answered. The
   two are told apart by the presence of a billing address, not by the
   `__experimental_calc_totals` flag those requests also carry.
2. The classic `validate_fields()` also runs during a Blocks payment, where
   the correction arrives as Store API payment data rather than in `$_POST`.
   Left alone it refused orders that had been answered correctly, so it
   returns early for Store API requests and lets the Blocks gate own them.

Rejected: one always visible field on both pages. It duplicates a field
WooCommerce already renders, and asks every card shopper for a number the
payment never uses. The plugin already refused the same trade once, which is
why the combined session does not set `phoneNumberCollection`: that flag is
session wide, so it would make the phone required for card shoppers too.

## The gap this does not close

The server only requires a phone when `phoneNumberCollection` is set, and
that flag is banned in Elements mode, so the check never runs
(`checkout-session-collect.service.ts:236`). The API's own field is declared
`@IsOptional() @IsString()` with no format rule. Our validation is the only
gate, by decision rather than by oversight.

## Where the rules live

Two questions that look like one, kept in separate classes so neither drifts
into answering the other:

- `XPay_Phone` canonicalises to E.164 and judges nothing else. A Cairo
  landline and a British mobile both come back canonical, because both are
  real numbers and a card shopper's contact detail is none of valU's
  business.
- `XPay_Wallet_Phone` holds the mobile plans and answers whether a number
  can be charged. Egypt is `1[0125]` plus eight digits, Jordan is `7[789]`
  plus seven. Anything else returns null and the shopper is asked.

The plans started out inside `XPay_Phone`, which was wrong in a way that
only showed when Jordan was added: it made "is this a real number" and "can
valU spend it" the same question, and would have refused a card shopper's
landline as their contact number.

## Still open

- Whether the correction prompt blocks the pay button or only warns.
- The pay page keeps the drop in window until the checkout page route is
  proven.
- Whether a Jordanian shopper should see Jordanian example numbers in the
  prompt's placeholder, which is currently an Egyptian one.
