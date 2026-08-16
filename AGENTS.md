# AGENTS.md — XPay for WooCommerce

This is production payment code. Every shortcut becomes a merchant's lost
order or a support ticket. The rules below are the engineering standard for
this repo — they were extracted from the XPay v3 monorepo's conventions and
the v2 plugin's hard-won lessons, and they are enforced in review and CI.

## Hard rules

- **No TODOs, no workarounds, no backward-compatibility shims.** Fix it
  properly or don't ship it. v3.0 is a clean break from the v2 API.
- **Comments explain WHY, never what** — the constraint, the rejected
  alternative, and (when a rule came from an incident) the exact symptom.
  A comment that restates the code gets deleted in review.
- **One source of truth per registry.** Error codes live in
  `XPay_Error_Codes`, event names in `XPay_Event_Names`, hosts/meta keys in
  `XPay_Constants`. Never inline a code, event string, host, or meta key.
- **Money is integers in minor units**, converted only by `XPay_Money`.
  Never float arithmetic on amounts, never a hardcoded `/ 100`.
- **Security fails closed.** Configured-but-failing verification rejects
  (401); missing plugin config answers 500 so XPay's retry engine keeps
  redelivering. Never silently downgrade to unverified-accept.
- **`hash_equals()` for every secret comparison** — signatures, session-id
  ownership checks. Never `===` on secret material.
- **Ownership, not existence**: any identifier arriving from outside must
  match the identifier THIS plugin stored on THAT order.
- **Credentials and base URLs resolve server-side only** — never from
  request input. (The v2 SSRF fix is a permanent rule.)
- **URLs from API responses are host-allowlisted** via
  `XPay_Constants::is_allowed_xpay_url()` before any redirect/embed.
- **Order-state transitions live only in `XPay_Order_Sync`** and are
  idempotent. Webhook and thank-you paths call the same methods.
- **Every `phpcs:ignore` carries a justification comment** naming why the
  rule doesn't apply. Bare suppressions fail review.
- **No magic numbers** — named constants with the unit and the reasoning.
- **Translatable strings**: text domain `xpay-for-woocommerce`, a
  `/* translators: */` comment on every placeholder string.
- **Structural layering**: WooCommerce hook methods are thin dispatch
  shims; business logic lives in services (`includes/<feature>/`).

## Voice (user-facing strings and docs)

Second person, active voice. No em dashes, no marketing adjectives
("seamless", "robust"), no hedge words ("might", "typically"). Shopper
errors never expose mechanism; merchant errors always carry the next
action and the API's `doc_url` when present.

## Testing

- PHPUnit tests colocated in `tests/`. Pure classes (`XPay_Money`,
  `XPay_Signature`) have full unit coverage.
- Invariant/regression tests open with a docblock naming the incident they
  pin. Sweep cases with data providers, never copy-pasted test methods.
- Never assert a value recomputed with the implementation's own formula.
- CI (phpcs + Plugin Check + PHPUnit) gates every PR. A task existing in a
  config is not enforcement — the workflow must actually run it.

## Release

- Keep a Changelog format in `CHANGELOG.md` with rationale-heavy entries
  and an explicit "Notes" section for what a release deliberately does NOT
  fix. `readme.txt`'s changelog is the compact WP.org mirror.
- `bin/build.sh` refuses to package unless the plugin header version, the
  `XPAY_WC_VERSION` constant, and readme.txt's stable tag agree.
- Nothing ships that isn't in the `.distignore`-filtered zip; no debug
  helpers in releases.
