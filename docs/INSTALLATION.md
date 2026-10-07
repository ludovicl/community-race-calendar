# Installation de Community Race Calendar

Ce guide décrit l'installation de l'extension WordPress Community Race Calendar. Toute première installation doit être validée sur une préproduction ou une copie locale du site avant la production.

## Prérequis

- WordPress 6.7 ou plus récent ;
- PHP 8.1 ou plus récent ;
- WooCommerce et WooCommerce Memberships actifs ;
- un compte WordPress administrateur ;
- le ZIP versionné de l'extension ;
- une sauvegarde récente des fichiers et de la base WordPress.

WooCommerce Subscriptions peut piloter le cycle commercial des adhésions, mais l'extension interroge uniquement WooCommerce Memberships pour déterminer si un membre possède une adhésion active.

Geoapify est facultatif. Sans clé Geoapify, les membres peuvent saisir librement un lieu, mais la course n'apparaît pas dans la vue Carte tant qu'elle ne possède pas de coordonnées.

## Environnement de test

1. Créer une préproduction ou une copie locale récente du site.
2. Ne pas exposer publiquement la copie et anonymiser les données personnelles si elles ne sont pas nécessaires aux tests.
3. Vérifier que les versions de WooCommerce et de WooCommerce Memberships correspondent à celles de la production.
4. Préparer au minimum trois comptes de test :
   - un administrateur WordPress ;
   - un membre avec une adhésion active ;
   - un utilisateur connecté avec une adhésion inactive.

## Stockage des logos

Par défaut, l’extension crée automatiquement son dossier dans les envois WordPress. Aucune configuration serveur n’est nécessaire. Les logos de courses sont considérés comme des contenus publics : leur URL directe peut être accessible même si le calendrier reste réservé aux adhérents.

Pour renforcer facultativement leur confidentialité, un administrateur serveur peut créer un répertoire persistant hors de `ABSPATH` et ajouter sa valeur dans `wp-config.php` :

```php
define('CRCAL_PRIVATE_UPLOAD_DIR', '/var/www/community-race-calendar-private');
```

Dans ce mode optionnel, le chemin doit être inscriptible par PHP et inclus dans les sauvegardes.

## Installer l'extension

1. Dans WordPress, ouvrir **Extensions > Ajouter une extension > Téléverser une extension**.
2. Sélectionner le fichier `community-race-calendar-x.y.z.zip`.
3. Installer puis activer l'extension.
4. Ouvrir **Réglages > Calendrier communautaire**.
5. Déplier **Informations techniques** et vérifier :
   - la version attendue de l'extension ;
   - une base de données indiquée comme à jour ;
   - WooCommerce Memberships comme contrôle des accès.
6. Si aucune page n'est associée, cliquer sur **Créer la page du calendrier**. La page est créée en brouillon avec le shortcode `[community_race_calendar]`.
7. Vérifier le contenu de la page, son URL et son intégration au site avant de la publier.

## Configurer l'extension

Dans **Réglages > Calendrier communautaire** :

1. Renseigner le nom du calendrier et son texte d'introduction.
2. Choisir si les membres actifs peuvent ajouter des courses.
3. Si plusieurs offres Memberships existent, sélectionner les adhésions autorisées. Sans sélection, toute adhésion active est acceptée.
4. Adapter les couleurs si nécessaire.
5. Laisser l'option de suppression des données à la désinstallation décochée.

### Activer la recherche de lieux

1. Créer une clé API dans Geoapify si l'autocomplétion et la vue Carte sont souhaitées.
2. Coller la clé dans **Recherche de lieux avec Geoapify**.
3. Cliquer sur **Enregistrer et vérifier la clé**.
4. Contrôler que l'état affiche **Connexion validée**.

La clé est stockée côté serveur, n'est jamais réaffichée et n'est pas envoyée au frontend. Une constante `CRCAL_GEOAPIFY_API_KEY` peut servir de secours dans `wp-config.php`, mais la clé enregistrée dans WordPress reste prioritaire.

## Cache

L’extension marque automatiquement la page et ses réponses privées comme non cachables. Aucune configuration supplémentaire n’est nécessaire avec une configuration WordPress standard. Si une règle Cloudflare personnalisée force **Cache Everything**, elle doit exclure la page calendrier et `/wp-json/community-race-calendar/v1/*`.

## Validation avant production

La mise en production est recommandée uniquement si les contrôles suivants réussissent sur l'environnement cible :

- la version de l'en-tête du plugin, du frontend, du `Stable tag` et du ZIP est identique selon `bash scripts/verify-release-consistency.sh` ;
- un visiteur est invité à se connecter et ne voit aucune donnée du calendrier ;
- le membre actif peut consulter le calendrier, créer une course, s'inscrire, proposer un covoiturage et commenter même sans inscription, puis publier un résultat s'il est inscrit ;
- le membre inactif reçoit un refus d'accès ;
- l'administrateur peut modifier une course, inscrire un membre et modérer les commentaires ;
- une course avec un lieu libre est enregistrée ;
- une sélection Geoapify affiche un aperçu et place la course sur la carte ;
- un logo peut être ajouté ; son URL directe est publique avec le stockage standard, l'interface et la route du plugin restent réservées aux membres ;
- l'affichage est correct sur ordinateur et mobile, sans débordement horizontal ;
- aucune page privée ni réponse REST n'est servie depuis un cache public ;
- les autres pages du site fonctionnent normalement.

## Mise à jour

1. Sauvegarder la base WordPress et le répertoire privé.
2. Installer le nouveau ZIP en remplaçant la version existante.
3. Réactiver l'extension si WordPress le demande.
4. Ouvrir **Réglages > Calendrier communautaire**.
5. Vérifier la version de l'extension, l'état de la base et l'absence de notice de migration.
6. Rejouer les contrôles essentiels : accès actif et inactif, lecture, création, inscription et logo privé.

La désactivation et les mises à jour conservent les données du calendrier.

## Rollback

1. Repasser la page du calendrier en brouillon si elle ne doit plus être accessible.
2. Désactiver Community Race Calendar.
3. Réinstaller le dernier ZIP validé.
4. Réactiver l'extension et vérifier les données.
5. Restaurer la sauvegarde de la base et du répertoire privé uniquement si le rollback du ZIP ne suffit pas.

Ne pas désinstaller l'extension pendant un rollback. La désinstallation peut supprimer définitivement les tables si l'option avancée correspondante a été activée.

