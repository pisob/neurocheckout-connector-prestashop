# NeuroCheckout Connector — PrestaShop

Dépôt officiel : https://github.com/pisob/neurocheckout-connector-prestashop

## Statut : préversion staging signée

La série `v4.6.0-preview.*` est destinée à la validation avec NeuroCheckout
Community `v0.1.0-preview.7` et le Cloud staging. Elle ne doit pas être connectée
à la production. Installer uniquement une archive officielle signée, jamais la
branche `main` directement.

Les agents, décisions, workers, quotas et envois d'emails restent dans
NeuroCheckout Cloud, dont le code n'est pas inclus ici. Community est
l'interface auto-hébergée, pas un moteur Cloud autonome.

Après un test API staging réussi, le connecteur expose automatiquement à
Community l'instantané signé des produits et paniers de la boutique courante.
Le secret dédié est dérivé de la clé connecteur existante : aucun second secret,
fichier serveur ou accès SSH n'est demandé à l'utilisateur. Les données complètes
restent dans le coffre local chiffré de Community ; seuls des signaux minimisés
sont relayés vers le Cloud.

## Installation et environnement de test

### Télécharger le bon fichier

La préversion actuellement compatible avec NeuroCheckout Community
`v0.1.0-preview.7` est **PrestaShop Connector `v4.6.0-preview.1`** :

- [Télécharger directement le module installable `.zip`](https://github.com/pisob/neurocheckout-connector-prestashop/releases/download/v4.6.0-preview.1/neurocheckoutconnector-prestashop-4.6.0-preview.1.zip)
- [Consulter la release officielle et ses fichiers de vérification](https://github.com/pisob/neurocheckout-connector-prestashop/releases/tag/v4.6.0-preview.1)

Ne pas utiliser **Code → Download ZIP** sur la page principale de GitHub : ce
bouton télécharge le dépôt de développement complet, que PrestaShop ne peut pas
installer comme module. Ne pas décompresser le ZIP officiel avant son import.

### Vérifier le téléchargement (recommandé)

La release contient également `SHA256SUMS`, `SHA256SUMS.asc` et
`RELEASE-PUBLIC-KEY.asc`. Depuis le dossier de téléchargement :

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

Continuer uniquement si l'empreinte est exactement
`9E34 8371 86C1 946E D747 7987 D715 1C30 7080 415D`, si GPG indique une bonne
signature de `NeuroCheckout Connector Release <contact@neurocheckout.com>` et
si le contrôle du ZIP affiche `OK`. L'avertissement de confiance GPG est normal
lors d'une première importation ; une mauvaise signature ne l'est pas.

### Installer dans PrestaShop

1. Sauvegarder la boutique et sa base de données avant de remplacer un ancien
   connecteur.
2. Dans le back-office PrestaShop, ouvrir **Modules → Gestionnaire de modules**.
3. Cliquer sur **Installer un module**.
4. Sélectionner sans le décompresser
   `neurocheckoutconnector-prestashop-4.6.0-preview.1.zip`.
5. Attendre la confirmation d'installation, puis ouvrir **Configurer**.
6. Renseigner l'endpoint `https://community-api-staging.neurocheckout.com`, la
   clé API dédiée à la boutique et l'identifiant externe exact de la boutique.
7. Enregistrer, puis cliquer sur **Test API**. Le test doit réussir avant que les
   événements et la synchronisation locale soient activés.
8. Laisser NeuroCheckout Community `v0.1.0-preview.7` en fonctionnement. Après
   sa connexion au Cloud staging, le coffre chiffré et la synchronisation des
   produits/paniers s'activent automatiquement ; aucun second secret, fichier
   serveur ou accès SSH n'est nécessaire.

Cette préversion est réservée à une boutique de test staging. Ne pas utiliser
l'URL de production. Ne jamais désinstaller sans sauvegarde : les données locales
du connecteur et certains liens de récupération pourraient être perdus.
Aucune boutique n'est modifiée par la simple consultation ou le téléchargement
du dépôt.

Utiliser uniquement une clé API connecteur émise pour la boutique et
l'environnement sélectionnés ; le Client ID OAuth Community n'est pas cette clé.
Ne jamais committer de clés, données clients, fichiers .env ou exports de base.
Vérifier les consentements et les paramètres de données avant connexion au Cloud.

## Validation

```bash
python3 tools/validate.py
```

Ces contrôles exécutent le lint PHP et des tests isolés avec données synthétiques.
Ils ne remplacent pas les tests d'installation, migration, cron, achat, rotation
de clé et désinstallation sur les versions réelles de PrestaShop.
Les contrôles CI ne disposent d'aucun secret staging ou production.

## Contributions et releases

Les contributions externes ne sont pas encore ouvertes. Voir [CONTRIBUTING.md](CONTRIBUTING.md).
Les releases exigent une validation manuelle, des tests staging, un checksum et
une signature vérifiable ; aucun workflow de publication automatique n'est fourni.
Voir [RELEASING.md](RELEASING.md) et [SECURITY.md](SECURITY.md).

## Licence et marque

Code du connecteur : **Apache-2.0**, voir [LICENSE](LICENSE).
Les notices tierces sont conservées. Cette licence ne transfère pas les droits
sur la marque NeuroCheckout et ne donne pas accès au code privé du Cloud.
Une copie modifiée ne doit pas être présentée comme une version officielle.
