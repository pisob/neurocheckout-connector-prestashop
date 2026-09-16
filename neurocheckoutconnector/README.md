# NeuroCheckout Connector for PrestaShop

Version 4.6.4 preserves synchronization state outside the PrestaShop cache,
exports native cart amounts, and includes cover image IDs for Community product
snapshots.

Download the installable ZIP and signature verification files from the
[official release](https://github.com/pisob/neurocheckout-connector-prestashop/releases/tag/v4.6.4).
Follow the [installation and verification instructions](https://github.com/pisob/neurocheckout-connector-prestashop#readme).

To upgrade, back up your store and upload the module ZIP through
**Modules → Module Manager → Upload a module**. Do not uninstall the existing
module. The upgrade preserves tables, credentials, settings and queued events.

Keep Community online for local synchronization and recovery processing.
Never share connector keys or customer records in public issues.

### Persistent synchronization state

Synchronization metadata is kept in `var/neurocheckout-community-source` under
the store root, outside the cache and module directories. Existing cache-based
state is moved automatically, preserving pending records and revision counters.
Do not delete this directory when clearing caches; include it in store backups.
State files retain restrictive permissions and encrypted record storage.
Apache access-denial rules are included. If you use Nginx, deny public access to
`/var/neurocheckout-community-source/` in your web server configuration.
If this directory is lost, restore a consistent backup instead of resetting it.
Include the adjacent `var/.neurocheckout-community-source-migration.lock` marker
in the same backup.
