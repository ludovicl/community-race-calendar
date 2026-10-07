# Utilisation de Community Race Calendar

Community Race Calendar permet aux membres actifs de la communauté de consulter les prochaines courses, de proposer une course, de s'inscrire sur un format, d'organiser un covoiturage et de publier leurs résultats depuis leur compte WordPress habituel.

## Accéder au calendrier

1. Ouvrir la page du calendrier sur le site de la communauté.
2. Se connecter depuis la page **Mon compte** si nécessaire.
3. Vérifier que l'adhésion WooCommerce Memberships est active.

Un utilisateur non connecté est invité à se connecter. Un utilisateur connecté sans adhésion active ne peut consulter ni les courses, ni les participants, ni les commentaires.

## Consulter les courses

- Utiliser l'onglet **Calendrier** pour parcourir les courses par mois.
- Utiliser la recherche pour filtrer par nom de course ou lieu.
- Cliquer sur une carte pour ouvrir la fiche complète, voir les formats et les participants.
- Utiliser l'onglet **Carte** pour afficher les courses qui possèdent des coordonnées.
- Cliquer sur un marqueur, puis sur **Voir la fiche complète**, pour ouvrir la course.
- Utiliser l'onglet **Revue de presse** pour choisir les dates des courses à inclure. La semaine précédente, du lundi au dimanche, est proposée par défaut.
- Utiliser l'onglet **Bilan** pour consulter les chiffres et classements de la saison.

Une course enregistrée avec un lieu libre reste visible dans le calendrier même si elle n'apparaît pas sur la carte.

## Créer une course

La création est disponible si l'administrateur l'a autorisée pour les membres actifs.

1. Cliquer sur **Ajouter une course**.
2. Renseigner le nom et la date.
3. Saisir le lieu de départ.
4. Si des propositions de lieux apparaissent, en choisir une pour enregistrer les coordonnées et afficher un aperçu cartographique. Le lieu peut aussi être conservé sans sélection.
5. Ajouter facultativement :
   - le site officiel de la course ;
   - un logo JPG, PNG ou WebP de 5 Mo maximum.
6. Ajouter chaque distance ou parcours dans un format séparé, par exemple `50 km`, `100 km`, `OCC` ou `CCC`.
7. Cliquer sur **Enregistrer**.

Le créateur peut ensuite ouvrir la fiche et cliquer sur **Modifier**. S’il supprime sa course, elle disparaît du calendrier mais conserve ses formats, inscrits, commentaires, résultats et logo. Seul le créateur peut supprimer ou restaurer sa course. Une icône de corbeille discrète, à côté de la recherche, ouvre **Supprimées récemment**.

Si une course du même nom existe déjà dans le même mois, le formulaire affiche un avertissement avec sa date et son lieu. Cet avertissement n’empêche pas l’enregistrement.

## S'inscrire à une course

1. Ouvrir la fiche de la course.
2. Repérer le format souhaité.
3. Cliquer sur **S'inscrire sur…**.

Il est possible de s'inscrire sur plusieurs formats de la même course. Pour se désinscrire, cliquer sur la croix affichée à côté de son nom dans le format concerné.

## Organiser un covoiturage

Le covoiturage est ouvert à tous les membres actifs, qu’ils participent à la course ou accompagnent un coureur.

1. Ouvrir la fiche de la course.
2. Choisir **Je conduis** ou **Je cherche une place**.
3. Pour un conducteur, indiquer le nombre de places disponibles.
4. Ajouter le lieu de départ et un message utile.
5. Cliquer sur **Publier**.

La proposition peut être modifiée ou supprimée par son auteur. Elle reste disponible même si le membre n’est pas inscrit à la course.

## Publier un résultat

1. Ouvrir la fiche d'une course terminée.
2. Choisir l'un des formats auxquels le membre était inscrit.
3. Renseigner au moins le temps, le classement, la distance réalisée ou une note.
4. Cliquer sur **Enregistrer le résultat**.

Le résultat rejoint la revue dans l'onglet **Revue de presse**. Choisir les dates **Du** et **Au**, puis cliquer sur **Afficher**. Le bouton **Copier la revue** prépare un texte réutilisable dans les canaux de la communauté.

## Consulter le bilan annuel

L'onglet **Bilan** synthétise la saison sélectionnée : courses du calendrier, inscriptions, participants, activité par mois, membres les plus présents et courses les plus suivies. Ces statistiques utilisent uniquement les données du calendrier communautaire.

## Commenter

Les commentaires sont ouverts à tous les membres actifs, même non inscrits à la course.

1. Ouvrir la fiche de la course.
2. Saisir le message dans **Ajouter un commentaire…**.
3. Cliquer sur **Envoyer**.

Un membre peut supprimer son propre commentaire. Les commentaires sont en texte brut et limités à 1 000 caractères.

## Administrer le calendrier

Les administrateurs WordPress disposent de droits supplémentaires dans le calendrier :

- créer et modifier toutes les courses ;
- désinscrire un membre actif ;
- supprimer n'importe quel commentaire ;
- configurer l'apparence, Geoapify et les droits de création depuis **Réglages > Calendrier communautaire**.

### Ajouter un autre membre

1. Ouvrir la fiche de la course en tant que membre actif.
2. Dans **Ajouter une personne**, saisir au moins deux caractères du nom ou du login d’un membre actif.
3. Sélectionner le membre et le format.
4. Cliquer sur **Ajouter**.

La recherche exclut automatiquement les utilisateurs sans adhésion active. Pour retirer une inscription, cliquer sur la croix placée à côté du nom du membre.

## Modérer

### Modifier, supprimer ou restaurer une course

1. Ouvrir sa fiche.
2. Cliquer sur **Modifier** pour corriger ses informations.
3. Si vous êtes le créateur, cliquer sur **Supprimer** pour la placer dans les éléments supprimés, puis confirmer l'action.

Pour la restaurer, revenir au calendrier, ouvrir l’icône de corbeille et cliquer sur **Restaurer**. Les contributions et le logo sont conservés pendant toute la suppression réversible.

### Supprimer un commentaire

Dans la fiche de la course, cliquer sur l'icône de suppression du commentaire concerné. L'auteur peut supprimer son propre commentaire ; un administrateur peut supprimer tous les commentaires.

## En cas de problème

- **Connexion requise** : ouvrir **Mon compte**, se connecter puis revenir au calendrier.
- **Adhésion inactive** : vérifier l'état de l'adhésion WooCommerce Memberships.
- **Course absente de la carte** : sélectionner une proposition de lieu lors de la création ou de la modification.
- **Logo refusé** : utiliser un fichier JPG, PNG ou WebP de moins de 5 Mo.
- **Création indisponible** : demander à un administrateur si l'ajout de courses par les membres est activé.
- **Calendrier indisponible** : transmettre le message affiché à l'administrateur WordPress sans réessayer plusieurs modifications.
