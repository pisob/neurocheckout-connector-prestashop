# NeuroCheckout Connector for PrestaShop

This is the official open-source PrestaShop connector for NeuroCheckout. It
sends authenticated store events to NeuroCheckout Cloud and provides signed,
read-only product and cart snapshots to the encrypted local vault in
NeuroCheckout Community.

## Download

The connector compatible with NeuroCheckout Community `v0.1.0-preview.9` is
`v4.6.3`:

- [Download the installable module ZIP](https://github.com/pisob/neurocheckout-connector-prestashop/releases/download/v4.6.3/neurocheckoutconnector-prestashop-4.6.3.zip)
- [View the official release and verification files](https://github.com/pisob/neurocheckout-connector-prestashop/releases/tag/v4.6.3)

Do not use **Code → Download ZIP**. That archive contains the complete source
repository and is not an installable PrestaShop module. Do not extract the
official module ZIP before uploading it to PrestaShop.

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
4. Upload `neurocheckoutconnector-prestashop-4.6.3.zip` without
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
connection at most once every 24 hours. When an update is available, its
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
