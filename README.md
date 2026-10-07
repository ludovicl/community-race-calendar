# Community Race Calendar

Plugin WordPress de calendrier de courses réservé aux membres d'une communauté de coureurs. Il réutilise les comptes WordPress et les adhésions existantes, sans gérer de comptes, de mots de passe ni d'abonnements.

Licence : GPL-2.0-or-later (voir `LICENSE`). Auteur : Ludovic Lardies, <https://lardies.fr>.

## Fonctionnalités

- calendrier mensuel des courses avec formats (distances ou parcours), site officiel et logo facultatif ;
- inscription des membres sur un ou plusieurs formats, ajout d'un autre membre actif ;
- vue Carte des courses qui possèdent des coordonnées GPS ;
- covoiturage conducteur ou passager, avec lieu de départ, places et message libre ;
- résultat personnel après la course, regroupé dans une revue hebdomadaire copiable ;
- bilan annuel avec nombre de courses, inscriptions, participants, activité mensuelle et classements ;
- commentaires, détection des doublons, suppression réversible par l'auteur ;
- réglages : nom, texte d'introduction, trois couleurs avec contrôle de contraste, plans d'adhésion autorisés ;
- export et effacement des données personnelles via les outils de confidentialité de WordPress.

## Prérequis

- WordPress 6.7 ou plus récent, PHP 8.1 ou plus récent ;
- WooCommerce Memberships (extension tierce, seul fournisseur d'accès livré) ;
- facultatif : une clé API Geoapify pour la recherche de lieux.

## Décision structurante

Le produit est un plugin WordPress installable, sans application ni base de données séparée :

- frontend React compilé, chargé uniquement sur la page qui contient le shortcode ; React et ReactDOM viennent de WordPress (`wp-element`, adaptateurs dans `frontend/src/wp-shims/`), seuls MapLibre et Lucide sont embarqués ;
- API REST et règles métier en PHP, toutes les autorisations sont appliquées côté serveur ;
- tables dédiées dans la base WordPress existante ;
- session utilisateur WordPress réutilisée (cookie et nonce `wp_rest`) ;
- moteur d'accès abstrait (`AccessProviderInterface`), avec l'adaptateur WooCommerce Memberships ;
- namespace PHP `CommunityRaceCalendar`, slug `community-race-calendar`, concepts techniques neutres.

## Services externes

Voir la section « External services » de `plugin/community-race-calendar/readme.txt` : Geoapify (recherche de lieux, côté serveur, uniquement si l'administrateur saisit une clé) et OpenFreeMap (fond de carte, chargé par le navigateur du membre qui ouvre la carte, uniquement si l'option « Carte » est cochée dans les réglages ; désactivée par défaut sur une nouvelle installation). Aucun script ni feuille de style n'est chargé depuis un serveur tiers.

## Arborescence

```text
.
├── docs/                           # architecture, installation, utilisation
├── frontend/                       # sources React (Vite), tests Vitest
├── plugin/community-race-calendar/ # plugin WordPress et assets compilés
├── compose.yaml                    # WordPress local isolé
├── compose.package-test.yaml       # installation et mise à jour du ZIP
├── compose.matrix.yaml             # matrice PHP et WordPress
├── bin/                            # installation locale, tests, packaging
├── scripts/                        # contrôle de cohérence des versions
└── tests/                          # tests PHP d'intégration et fixture Memberships
```

## Compiler le frontend

Prérequis : Node.js 20 ou plus récent et npm.

```bash
cd frontend
npm ci
npm run build
```

Le build écrit `app.js`, `app.css`, le dossier `chunks` et le worker MapLibre dans `plugin/community-race-calendar/assets/`. Les fichiers compilés sont versionnés pour que le plugin reste installable sans Node.js, et le build est reproductible : la CI recompile et échoue si les fichiers diffèrent.

## Développement local

Prérequis : Docker Desktop, Node.js 20 ou plus récent, npm, `curl` et `zip`.

```bash
./bin/setup-local.sh
```

Le script démarre WordPress sur `http://localhost:8087`, active le plugin, crée une page `/calendrier/` et des comptes strictement locaux. La fixture Memberships vit dans `tests/fixtures/` et ne fait jamais partie du ZIP.

Commandes de validation :

```bash
cd frontend && npm run check
cd .. && ./bin/test-backend.sh
./bin/test-http-gate.sh
./bin/build-zip.sh
./bin/test-package.sh
bash scripts/verify-release-consistency.sh
```

Tests complémentaires :

```bash
# Matrice PHP et WordPress (images Docker officielles, conteneurs supprimés à la fin)
./bin/test-matrix.sh p81wp67 wordpress:6.7-php8.1-apache wordpress:cli-php8.1 8202 smoke
# Test de fumée navigateur seul (nécessite puppeteer-core et un navigateur Chromium)
CRCAL_PUPPETEER_PACKAGE=/chemin/node_modules/puppeteer-core/package.json CRCAL_BROWSER=/chemin/navigateur node tests/browser/smoke.mjs enabled
```

## Shortcode et API

- Shortcode : `[community_race_calendar]`
- REST : `/wp-json/community-race-calendar/v1/`
- Capacité : `manage_community_race_calendar`
- Tables : `{prefix}community_race_courses`, `{prefix}community_race_formats`, `{prefix}community_race_registrations`, `{prefix}community_race_comments`, `{prefix}community_race_carpools`, `{prefix}community_race_results`

## Images des courses

Une course peut recevoir une image JPG, PNG ou WebP de 5 Mo maximum, ré-encodée (WebP si possible, 1 600 × 900 px maximum, qualité 82) puis stockée avec un nom aléatoire dans un sous-dossier des envois WordPress. Un `index.php` vide empêche le listage du dossier, mais les logos restent des contenus publics : leur URL directe peut être ouverte par quiconque la connaît. `CRCAL_PRIVATE_UPLOAD_DIR` permet de choisir un dossier privé hors de la racine web.

## Documents

- [Architecture](docs/ARCHITECTURE.md)
- [Installation, configuration, validation et retour arrière](docs/INSTALLATION.md)
- [Utilisation et administration](docs/UTILISATION.md)

## Limites de certification

La suite locale utilise une fixture limitée aux fonctions publiques de WooCommerce Memberships. Elle s'arrête automatiquement si `wp_get_environment_type()` vaut `production`, et le test de packaging refuse toute fixture dans le ZIP. La compatibilité avec les versions réelles de WooCommerce Memberships reste à valider sur chaque site cible.
