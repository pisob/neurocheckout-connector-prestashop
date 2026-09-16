# Changelog

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
