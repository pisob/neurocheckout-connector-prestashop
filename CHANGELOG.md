# Changelog

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
