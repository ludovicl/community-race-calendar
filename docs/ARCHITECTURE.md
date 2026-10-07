# Architecture de Community Race Calendar

## Frontière générique

Le plugin sépare quatre responsabilités :

```text
Frontend React
    │ cookie WordPress + X-WP-Nonce
    ▼
Routes REST community-race-calendar/v1
    ├── AccessGate ── AccessProviderInterface
    │                     └── WooCommerceMembershipsAccessProvider
    ├── règles d’autorisation
    ├── CalendarRepository ── tables WordPress neutres
    ├── GeocodingProviderInterface
              └── GeoapifyGeocodingProvider ── API externe côté serveur
    └── CourseImageManager ── métadonnées WordPress + stockage automatique des logos
```

Le namespace PHP, le slug, le namespace REST, les tables, les options et la capacité ne contiennent aucun nom de club. Le nom, le texte d’introduction et les couleurs par défaut sont neutres et configurables.

## Accès

`AccessProviderInterface` est le seul contrat consommé par `AccessGate`. Le MVP livre exclusivement `WooCommerceMembershipsAccessProvider` :

- allowlist vide : au moins une adhésion active ;
- allowlist renseignée : au moins un plan autorisé actif ;
- dépendance absente : refus fermé pour les membres et notice administrateur ;
- administrateur calendrier : accès de maintenance conservé.

Les tests remplacent uniquement les fonctions publiques de Memberships dans un mu-plugin de fixture. Ce fichier n’est ni copié ni empaqueté avec le plugin.

## Données

Les migrations utilisent un verrou atomique, exécutent `dbDelta()`, puis vérifient les tables, colonnes et index attendus avant d’avancer `community_race_calendar_schema_version`. Elles s’exécutent à l’activation ou dans une requête administrateur, jamais opportunément sur une page publique. Tant que le schéma n’est pas vérifié, les endpoints métier répondent 503 et une notice administrateur conserve le diagnostic. La désactivation conserve les données. La désinstallation les conserve aussi par défaut.

Les transactions couvrent la création d’une course avec ses formats et la modification des formats. La suppression d’une course renseigne `deleted_at` et conserve toutes les relations afin de permettre une restauration par l’auteur. Les contraintes uniques empêchent les doublons de format et les doubles inscriptions ; une comparaison normalisée et approximative des noms signale les doublons possibles du même mois sans bloquer l’enregistrement.

Le schéma 1.1.0 ajoute `latitude` et `longitude` nullables aux courses. Une sélection Geoapify fournit les deux coordonnées ; un lieu saisi librement reste valide sans point GPS. Ces courses restent visibles dans le calendrier mais sont absentes de la vue Carte.

Le schéma 1.2.0 ajoute `image_id`. La valeur `0` signifie qu’aucune image n’est associée. `CourseImageManager` valide, redimensionne à 1 600 × 900 px maximum, applique une qualité de 82 et convertit en WebP lorsque l’éditeur WordPress le permet avant de créer une pièce jointe marquée comme gérée par le plugin. Le remplacement est préparé avant l’écriture métier ; en cas d’échec, le nouveau média est supprimé et l’ancien reste intact. Le média est supprimé lors de son retrait explicite, mais conservé pendant la suppression réversible de la course.

Le schéma 1.5.0 ajoute `deleted_at` aux courses et l’index `deleted_creator`. Les lectures normales, la carte, le bilan et la revue excluent ces lignes. `/courses/deleted` ne retourne que les courses créées par l’utilisateur courant ; `/restore` remet `deleted_at` à `NULL` après contrôle serveur de l’auteur.

Le schéma 1.7.0 retire la table de ravitaillement introduite brièvement en 1.6.0 afin de recentrer le produit sur les commentaires et le covoiturage.

Le schéma 1.3.0 a introduit le stockage privé sans ajouter de table. Depuis la 0.5.16, lorsqu’aucun `CRCAL_PRIVATE_UPLOAD_DIR` n’est configuré, le fichier réencodé est stocké automatiquement dans un sous-dossier des envois WordPress avec un nom aléatoire. La route `/images/{id}` continue de vérifier l’adhésion et de diffuser le fichier avec `no-store`, mais l’URL directe du fichier standard peut être publique. Une installation existante avec `CRCAL_PRIVATE_UPLOAD_DIR` conserve son stockage privé et son comportement antérieur.

Le schéma 1.4.0 ajoute les tables `carpools` et `results`. Chaque contribution appartient à un membre et à une course, avec une contrainte unique par paire course-membre. Tout membre actif peut contribuer au covoiturage, tandis qu’un résultat exige toujours une inscription existante. La dernière désinscription d’un membre sur une course retire son résultat mais conserve son covoiturage éventuel. L’endpoint `/season` agrège uniquement des données déjà visibles par les membres actifs et ne crée aucune donnée parallèle.

## Géocodage et cartographie

`GeocodingProviderInterface` isole le fournisseur de recherche. Le MVP 0.2.0 livre uniquement `GeoapifyGeocodingProvider`. La clé est lue en priorité depuis l’option WordPress dédiée non autoloadée afin qu’un administrateur puisse la remplacer sans intervention technique. La constante `CRCAL_GEOAPIFY_API_KEY` sert de secours, puis le filtre serveur `community_race_calendar_geoapify_api_key` permet l’intégration avancée. Elle n’est jamais réaffichée dans l’administration, incluse dans le bootstrap ou injectée dans un asset.

La route `/locations` est réservée aux membres autorisés, valide la requête, applique une limite par utilisateur et met les résultats en cache. Le frontend annule la requête précédente et permet de choisir explicitement une proposition. L’état distingue clé configurée, état opérationnel connu et clé refusée. Après sélection, MapLibre affiche le point sur le fond de carte OpenFreeMap (données OpenStreetMap) avec attribution, dans un aperçu non interactif. La vue Carte générale conserve ses interactions de consultation.

## Sécurité

- Cookie de session WordPress et nonce REST standard.
- `permission_callback` explicite sur chaque route.
- Autorisation ressource recalculée avant chaque modification ou suppression.
- Requêtes préparées et écritures via `$wpdb`.
- Données entrantes assainies, dates validées et URL limitées à HTTP/HTTPS.
- Commentaires en texte brut, limités à 1 000 caractères et échappés par React.
- Aucun email, mot de passe, PIN, donnée de paiement ou détail d’adhésion exposé au frontend.
- Recherche et ajout de membres ouverts à tout adhérent actif ; désinscription d’un tiers réservée à l’administration.
- Recherche de lieux authentifiée, limitée et sans exposition de la clé fournisseur.
- Images limitées à 5 Mo, 10 000 px par côté et 40 millions de pixels, réencodées après détection réelle JPG, PNG ou WebP.
- Limites par utilisateur sur créations, inscriptions, commentaires, covoiturages, résultats, géocodage et téléversements.
- Exporteur et effaceur WordPress paginés : inscriptions, covoiturages et résultats supprimés, commentaires et courses anonymisés.

## Frontend

Le client REST applique un timeout de 15 secondes par défaut, conserve
l'annulation demandée par les vues et distingue les pannes réseau, timeouts,
erreurs HTTP, réponses non JSON et schémas JSON primitifs. Les erreurs exposées
à l'interface ont un message français actionnable, un code stable et le statut
HTTP quand il existe.

Vite produit un module ES principal et une feuille de style dans le plugin. MapLibre est séparé dans un chunk chargé à la demande. Les assets sont chargés uniquement si le shortcode est rendu. Tous les styles applicatifs sont confinés sous `.crc-app`, sans reset global. La fenêtre métier est calculée côté PHP puis envoyée au frontend : mois précédent, mois courant et dix mois suivants. Les bornes sont appliquées au champ date et à l’API.

Le thème de présentation par défaut est neutre : texte sombre, accent bleu, polices système et contenu limité à 1 200 px. La couleur configurée comme `brand_color` sert d’accent aux actions et états actifs ; la bannière interne reste sombre afin de s’intégrer au header et aux sections du site. Les marqueurs et actions interactives mesurent au moins 40 × 40 px, les modales piègent le focus et deviennent des bottom sheets sous 520 px.

Les images utilisent un cadre plafonné avec `object-fit: contain` afin d’afficher complètement les logos sans les agrandir ni les recadrer. La même image privée, déjà bornée à 1 600 × 900 px, est servie dans la carte et la fiche ; aucun dérivé public ni `srcset` WordPress n’est créé. Une course sans image conserve le composant texte compact existant.
