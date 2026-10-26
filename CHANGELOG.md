## 4.6.14

- Export shop-scoped combination images so Community emails can show the selected variant.
- Preserve the product cover fallback for variants without a specific picture.
- Keep credentials, queues and encrypted source snapshots during in-place upgrades.

## 4.6.12

- Add signed, bounded native account discovery for initial and weekly local history synchronization.

## 4.6.11

- Add signed native account-cart presence and history v2 discount evidence.
- Keep native facts bounded, read-only, scoped and compatible with v1 validation.
- Require coordinated Community/Cloud support; no automatic consent or sender activation.

# Changelog

## 4.6.9

- Initialize the scoped cart context during early cart-save events, preventing
  PrestaShop 1.7 recovery links from terminating before checkout can load.
- Restore the visitor's previous cart context after calculation, including failures.
- Report the actual installed connector version from cron without an admin visit.
- Retry failed version checks after five minutes instead of caching failure for a day.
- Preserve credentials, settings, queues and persistent source state on upgrade.
- Validate isolated PHP 7.4/8.3 regressions and the recovered-cart checkout on a
  local PrestaShop 1.7.8.11 test store; this is not full platform-matrix acceptance.

## 4.6.8

- Fix read-only Community snapshot transactions on PrestaShop 1.7.8.11 / PHP 7.4,
  including repeated reads and cleanup after a rejected shop scope.
- Show actionable PHP, extension and incomplete-package diagnostics before
  installation or configuration can fail.
- Preserve merchant credentials, queued events and persistent synchronization
  state during an in-place update. Add runtime and upgrade regression coverage.

## 4.6.7

- Preserve native customer guest status in Community cart snapshots.

## 4.6.6

- Accept the official `https://www.neurocheckout.com` API address while retaining
  strict HTTPS and host validation.
- Show configuration errors in the module instead of an HTTP 500 error page.
- Reject malformed fields and empty store identifiers before saving, detect
  configuration write failures, and avoid sensitive exception details in logs.
- Preserve an existing API key when its input is blank and require a new API test
  after connection settings change. Add configuration and upgrade regression tests.

## 4.6.5

- Support Community synchronization with production accounts.
- Keep environment-specific authorization and synchronization state separate.
- Preserve existing credentials, settings and queues during upgrades.

## 4.6.4

- Export PrestaShop cover image IDs in Community product snapshots so
  NeuroCheckout recovery emails can render cart and recommendation product
  images instead of placeholders.
- Preserve all connector credentials, queues, settings and local synchronization
  state during the in-place upgrade.

## 4.6.3

- Keep Community synchronization state in `var/neurocheckout-community-source`,
  outside the disposable PrestaShop cache and the module installation directory.
- Automatically move existing state without resetting streams, cursors, pending
  records, revision counters or replay protection. A busy source is retried safely.
- Refuse to silently reinitialize a previously initialized durable state directory
  if it goes missing. Include this directory in store backups.

## 4.6.2

- Export native checkout totals, tax-exclusive totals, unit prices and line totals
  to the encrypted Community vault. Discounts and native rounding are preserved.
- Reject inconsistent cart pricing and report unavailable amounts explicitly.
  A cart that cannot be priced does not block other carts or conversions.
- Restore the original PrestaShop context after pricing, including on failure.
- Preserve connector tables, credentials, queues and synchronization state during
  an in-place upgrade. No uninstall or database migration is required.

This release fixes cart amount synchronization. It does not introduce a new
email attribution mechanism. Cloud-side handling of cart amounts must also be
available for recovery decisions to use the synchronized total.
