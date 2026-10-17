# NeuroCheckout Connector for PrestaShop

## Native recipient prices (candidate)

With matching Community and Cloud candidates, the signed source now returns
native one-unit offers for a registered customer in the current shop. It uses
the customer's group, first native tax address and explicit shop currency;
native tax display precision and the exact default combination are preserved.
Shipping, cart vouchers and checkout totals are outside this contract. No
browser session, customer login, cart, consent or order is changed.

The reader qualifies native PrestaShop 1.7.8.x and was compared with
1.7.8.11/PHP 7.4. It requires InnoDB pricing tables and an owned read-only
consistent transaction. Unsupported overrides/custom tax or price hooks,
preinitialized pricing, incomplete fiscal context, packs/customization,
unsuitable minimum quantities and unavailable default-combination stock are
refused. There is no catalog base-price fallback. Requests contain at most 16
products; responses are limited to 16 KiB with 15-second freshness. SQL and lock
waits are bounded to one second within a three-second cooperative read budget.

Checks include 58 protocol/gateway and 69 disposable native-schema assertions
with API doubles on PHP 7.4/8.3, and eight actual native offers. The
optional `neurocheckoutconnector/tests/customer_price_runtime_probe.php` is
restricted to the dedicated local 1.7.8.11 fixture and requires
`NC_PS17_PRICE_PROBE=1`. Two signed candidate Community reads and private Cloud
validation passed, with replay rejection and no catalog/outbox persistence.
This is not a live HTTPS or email-delivery test. Final sending-policy and price
checks remain required. The installed module and stable download are unchanged.

## Native account history (candidate)

With matching Community and Cloud candidates, the signed source can read a
complete native account order history independently of retained carts. It binds
the active registered customer, email and current shop; it does not borrow guest
or other-shop orders. Native newsletter state is read without changing it.
The bounded, read-only repeatable-read transaction requires InnoDB and retains
metadata locks. More than 128 orders, 128 payments, 128 credit notes, 512 combined
lines or 64,000 bytes is unavailable, never silently truncated. SQL statements
and lock waits are bounded to one second, with a three-second cooperative budget.

Payments can span split orders: each payment appears once with its native order
relations, without an invented per-order allocation. Order totals, native paid
fields, state flags, credits and returned/refunded quantities remain separate
facts. A credit note does not establish a bank refund. Local native timestamps
are retained with the current runtime timezone, not claimed as proven historic
UTC. No address, name, payment method, card or transaction details are returned.
The existing HTTPS/HMAC, replay and rate guards apply; responses bind the exact
request, expire after 15 seconds, and are not cached or stored in the local vault.

Checks: 83 protocol and 58 disposable native SQL assertions on PHP 7.4/8.3,
including shared payments, foreign references, consent withdrawal during a
snapshot, schema/engine failures, capacity limits and caller transaction safety.
Native read-only comparison and the candidate Community bridge are additional
checks, not live HTTPS or delivered-email tests. This does not activate campaigns
or change installed stores. The current stable download below is unchanged.

## Customer identity in Community

Candidate: local snapshots now include the native newsletter preference for an
active, non-deleted customer belonging to this shop. Partner-offer opt-in, cart
creation and billing email are not substitutes. Consent from another shop is not
inherited, and unknown evidence blocks recommendations. The exporter never opts
customers in or invents consent timestamps. Cloud must recheck this state plus
unsubscribe and delivery policies before sending. This does not activate campaigns.
The disposable SQL regression retains the released shop-specific cover-image
selection alongside consent, scope and transaction tests.

Cart snapshots preserve the customer's native guest status. A customer ID or
email alone is not proof of registration. Missing customer records remain
unknown. Updating preserves merchant settings and cart references; a fresh
synchronization is required before validating registered-customer recovery.

This is the official open-source PrestaShop connector for NeuroCheckout. It
sends authenticated store events to NeuroCheckout Cloud and provides signed,
read-only product and cart snapshots to the encrypted local vault in
NeuroCheckout Community.

## Download

The current installable connector is `v4.6.9`:

- [Download the installable module ZIP](https://github.com/pisob/neurocheckout-connector-prestashop/releases/download/v4.6.9/neurocheckoutconnector-prestashop-4.6.9.zip)
- [View the official release and verification files](https://github.com/pisob/neurocheckout-connector-prestashop/releases/tag/v4.6.9)

Do not use **Code → Download ZIP**. That archive contains the complete source
repository and is not an installable PrestaShop module. Do not extract the
official module ZIP before uploading it to PrestaShop.

## Compatibility

Version 4.6.9 includes Community snapshot and recovered-cart context fixes on **PrestaShop 1.7.8.11 with
PHP 7.4**. The website and scheduled tasks must both use a compatible PHP version.
The module requires PHP 7.4 or newer, cURL and OpenSSL; missing requirements are
reported in the module administration screen. Use a PHP version supported by
your PrestaShop release. PHP 7.4 and PrestaShop 1.7 are legacy software: plan an
upgrade for production stores rather than treating module compatibility as a
security guarantee.

## Verify the download

Download `SHA256SUMS`, `SHA256SUMS.asc` and `RELEASE-PUBLIC-KEY.asc` from the
same release. In your download directory, run:

```bash
verification_home="$(mktemp -d)"
chmod 700 "${verification_home}"
GNUPGHOME="${verification_home}" gpg --batch --import RELEASE-PUBLIC-KEY.asc
GNUPGHOME="${verification_home}" gpg --batch --fingerprint \
  9E34837186C1946ED7477987D7151C307080415D
GNUPGHOME="${verification_home}" gpg --batch --verify SHA256SUMS.asc SHA256SUMS
sha256sum --check SHA256SUMS
find "${verification_home}" -depth -delete
unset verification_home
```

Continue only if the fingerprint is
`9E34 8371 86C1 946E D747 7987 D715 1C30 7080 415D`, GnuPG reports a good
signature from `NeuroCheckout Connector Release <contact@neurocheckout.com>`,
and the ZIP checksum reports `OK`.

## Install in PrestaShop

1. Back up the store and database.
   When upgrading an older connector, also preserve its
   `var/cache/*/neurocheckout-community-source` directory before the update.
   Migration requires the existing state; it cannot reconstruct deleted cursors.
2. Open **Modules → Module Manager** in the PrestaShop back office.
3. Select **Upload a module**.
4. Upload `neurocheckoutconnector-prestashop-4.6.9.zip` without
   extracting it.
5. Wait for installation to finish, then select **Configure**.
6. Enter the API endpoint, store-specific connector key and exact external store
   ID displayed in your NeuroCheckout account.
7. Save the configuration and select **Test API**. Event processing starts only
   after this test succeeds.
8. Keep NeuroCheckout Community online. Its encrypted local synchronization is
   configured automatically; no additional secret, server file or SSH access is
   required.

Never publish connector keys, customer records, cart contents or configuration
exports in an issue or pull request. Back up the store before uninstalling or
upgrading the module.

### Persistent synchronization storage

The connector moves its existing synchronization state automatically from the
PrestaShop cache to `var/neurocheckout-community-source` under the store root.
Clearing the cache or replacing module files must not remove this persistent
directory. Include it in backups; it preserves synchronization cursors, pending
records and version counters. For Nginx, deny public HTTP access to this path
(Apache denial rules are supplied). If persistent state is lost, restore a
consistent backup rather than resetting synchronization counters.
Back up the adjacent `var/.neurocheckout-community-source-migration.lock` marker
as well; it prevents missing state from being mistaken for a new installation.

## Updates

The connector checks its version through the existing authenticated Cloud
connection during cron and configuration visits. Successful observations are
cached for 24 hours; failures are retried no sooner than five minutes later, and
a changed installed version is reported without waiting for the daily cache.
When an update is available, its
configuration page displays the exact official GitHub release. The Cloud never
downloads files to the store and cannot install an update.

Back up the store, download the official module ZIP, then use **Module Manager →
Upload a module** without uninstalling the existing module. PrestaShop performs
an in-place upgrade because the technical module name remains
`neurocheckoutconnector`. Versioned upgrade scripts preserve connector tables,
credentials, settings and queued records. Never uninstall before upgrading.

## Development

The module source is located in `neurocheckoutconnector/`.

```bash
python3 tools/validate.py
```

The validation suite checks PHP syntax, endpoint policy, secret handling, HMAC
authentication, replay protection and Community source synchronization using
synthetic data. Platform-level installation and checkout tests should also be
completed before adopting a preview release.

## Contributions and releases

Submit changes through pull requests. Protected branches require automated
validation and maintainer review. External contributions cannot publish official
releases or access NeuroCheckout credentials.

Official releases are created from reviewed commits and include a signed tag,
SHA-256 checksums and a detached signature. See
[CONTRIBUTING.md](CONTRIBUTING.md), [RELEASING.md](RELEASING.md) and
[SECURITY.md](SECURITY.md).

## License and trademark

The connector source is licensed under Apache License 2.0. The NeuroCheckout
name and logos remain protected. Modified distributions must not claim to be
official NeuroCheckout releases.
