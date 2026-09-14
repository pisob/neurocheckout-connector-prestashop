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

Le module se trouve dans `neurocheckoutconnector/`. Le dossier racine du dépôt n'est pas un ZIP installable dans PrestaShop.

Préférer les futurs paquets officiellement signés pour une installation utilisateur.
Ne jamais désinstaller sans sauvegarder la base et la configuration : les données
locales propres au connecteur et certains liens de récupération peuvent être perdus.
Aucune boutique n'est modifiée par la publication de ce dépôt.

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
