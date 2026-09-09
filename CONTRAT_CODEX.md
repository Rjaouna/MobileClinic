# Contrat de Travail Codex - SymClinic

Ce document sert de contrat de collaboration pour le projet SymClinic. Codex doit le relire avant toute intervention importante sur ce dépôt et l'utiliser comme référence pour prendre des décisions cohérentes.

## 1. Objectif du projet

Construire un site Symfony professionnel, clair et maintenable pour SymClinic.

Le projet doit rester propre, évolutif et facile à reprendre par un développeur humain.

## 2. Règles de collaboration

- Codex explique brièvement ce qu'il va modifier avant de toucher aux fichiers.
- Codex inspecte toujours l'état existant du projet avant de proposer ou d'appliquer une modification.
- Codex ne supprime pas, ne remplace pas et ne revient pas en arrière sur des changements utilisateur sans demande explicite.
- Codex limite ses modifications au besoin demandé et évite les refontes non nécessaires.
- Codex signale clairement les points bloquants, les hypothèses et les commandes qui n'ont pas pu être exécutées.
- Codex ne crée pas de commit, de push ou de branche sans demande explicite.

## 3. Standards techniques Symfony

- Respecter les conventions Symfony déjà présentes dans le projet.
- Placer les contrôleurs dans `src/Controller`.
- Placer les templates Twig dans `templates`.
- Utiliser l'injection de dépendances Symfony plutôt que des appels globaux ou du code procédural.
- Utiliser les migrations Doctrine pour toute modification de schéma de base de données.
- Éviter d'ajouter une dépendance Composer ou npm sans raison forte et sans validation.
- Garder les variables sensibles dans `.env.local` ou dans l'environnement, jamais dans le code versionné.

## 4. Architecture obligatoire

La structure des contrôleurs doit toujours respecter cette arborescence :

```text
src/
└── Controller/
    ├── Public/
    │   ├── HomeController.php
    │   ├── ProductController.php
    │   └── AppointmentController.php
    │
    ├── User/
    │   ├── DashboardController.php
    │   ├── ReservationController.php
    │   ├── LoyaltyController.php
    │   └── ProfileController.php
    │
    ├── Admin/
    │   ├── DashboardController.php
    │   ├── ProductController.php
    │   ├── ReservationController.php
    │   ├── AppointmentController.php
    │   ├── LoyaltyController.php
    │   └── RecruitmentController.php
    │
    └── Api/
        ├── Public/
        │   └── ProductController.php
        │
        ├── User/
        │   ├── ReservationController.php
        │   └── ProfileController.php
        │
        └── Admin/
            ├── ProductController.php
            ├── ReservationController.php
            ├── AppointmentController.php
            ├── LoyaltyController.php
            └── RecruitmentController.php
```

La structure des templates Twig doit toujours respecter cette arborescence, avec l'extension commune `templates/components/` autorisée pour les composants globaux :

```text
templates/
├── public/
│   ├── home/
│   │   └── index.html.twig
│   │
│   ├── product/
│   │   ├── index.html.twig
│   │   └── show.html.twig
│   │
│   └── appointment/
│       └── index.html.twig
│
├── user/
│   ├── dashboard/
│   │   └── index.html.twig
│   │
│   ├── reservation/
│   │   ├── index.html.twig
│   │   ├── show.html.twig
│   │   └── modal/
│   │       ├── _cancel.html.twig
│   │       └── _show.html.twig
│   │
│   ├── loyalty/
│   │   └── index.html.twig
│   │
│   └── profile/
│       ├── index.html.twig
│       └── modal/
│           ├── _edit.html.twig
│           └── _password.html.twig
│
└── admin/
    ├── dashboard/
    │   └── index.html.twig
    │
    ├── product/
    │   ├── index.html.twig
    │   ├── show.html.twig
    │   ├── partial/
    │   │   ├── _table.html.twig
    │   │   └── _row.html.twig
    │   └── modal/
    │       ├── _add.html.twig
    │       ├── _edit.html.twig
    │       ├── _delete.html.twig
    │       └── _show.html.twig
    │
    ├── reservation/
    │   ├── index.html.twig
    │   ├── partial/
    │   │   └── _table.html.twig
    │   └── modal/
    │       ├── _edit.html.twig
    │       ├── _delete.html.twig
    │       └── _show.html.twig
    │
    └── appointment/
        ├── index.html.twig
        ├── partial/
        │   └── _table.html.twig
        └── modal/
            ├── _add.html.twig
            ├── _edit.html.twig
            ├── _delete.html.twig
            └── _show.html.twig
```

- Tout nouveau contrôleur doit être placé dans le sous-espace correspondant : `Public`, `User`, `Admin` ou `Api`.
- Tout template Twig doit être placé dans le dossier métier correspondant.
- Les dossiers `partial` sont réservés aux fragments réutilisables dans une page.
- Les dossiers `modal` sont réservés aux contenus de fenêtres modales.
- Toute modification de cette architecture doit être validée explicitement par le propriétaire du projet.

## 5. Composants réutilisables et design system

- Les blocs réutilisables du site doivent être codés comme des composants appelables, pas recopiés à plusieurs endroits.
- Les composants globaux peuvent être placés dans `templates/components/`. Les composants spécifiques à une page restent dans les dossiers `partial` ou `modal` du domaine concerné.
- Un composant doit accepter des paramètres simples pour changer son contenu ou son comportement, par exemple `title`, `label`, `action`, `href`, `route`, `variant`, `icon`, `disabled` ou `value`.
- Les boutons doivent être centralisés dans un composant réutilisable. On doit pouvoir changer le texte et l'action sans réécrire le HTML ou les classes CSS.
- Les couleurs des boutons doivent dépendre de l'intention de l'action : `primary`, `secondary`, `neutral`, `success`, `warning` ou `danger`.
- La couleur principale du projet est `#ec1c24`. Elle doit être utilisée pour les actions principales, les appels à l'action importants et les éléments actifs.
- Les couleurs, espacements, rayons, bordures et états de survol doivent être centralisés dans des variables CSS ou des classes communes, jamais dispersés en styles inline.
- Le site doit être construit en pleine largeur : les sections principales occupent 100% de l'écran avec des marges internes responsives, sans conteneur global étroit.
- Les textes longs peuvent garder une largeur de lecture locale pour rester confortables, mais la mise en page globale doit rester full width.
- La police principale du site est `Manrope`, avec une pile de secours système. Elle doit être utilisée pour l'interface et les pages publiques afin de garder un rendu moderne, lisible et adapté au service mobile care.
- Les composants attendus incluent au minimum : bouton, lien d'action, champ de recherche, bloc de prise de rendez-vous, badge, carte d'information, tableau, ligne de tableau, modale et alerte.
- Un champ de recherche doit être un bloc réutilisable configurable avec son libellé, son placeholder, sa valeur, sa méthode et son action.
- Un bloc de prise de rendez-vous doit être un composant réutilisable configurable avec son titre, son texte, son bouton et son action.
- Toutes les modales doivent utiliser un composant commun et garder la même taille, le même comportement plein écran, le même fond d'écran assombri, le même bouton de fermeture et les mêmes styles de formulaire.
- Les contenus propres à une modale peuvent changer, mais la structure visuelle de base ne doit pas être recodée différemment d'une page à l'autre.
- Le style général doit s'inspirer de la référence SYMA Mobile fournie : fond blanc, beaucoup d'air, rouge vif pour les actions, textes noirs lisibles, icônes simples, bordures fines gris clair, séparateurs discrets et boutons arrondis en pilule.
- L'identité SYMA Mobile ne doit pas être copiée directement : pas de logo, pas de marque, seulement une direction graphique cohérente adaptée à SymClinic.

## 6. Frontend et expérience utilisateur

- L'interface par défaut doit être en français.
- Les textes visibles en français doivent utiliser les accents corrects, par exemple `é`, `è`, `ê`, `à`, `ç` et `ù`.
- Le design doit être sobre, moderne, responsive et adapté à un site de clinique.
- Les pages doivent être lisibles sur mobile et desktop.
- Les boutons, formulaires et parcours importants doivent être accessibles au clavier autant que possible.
- Éviter les textes décoratifs inutiles : chaque bloc visible doit aider l'utilisateur.
- Ne pas créer une simple page marketing si l'utilisateur demande une fonctionnalité concrète.

## 7. Module prise de rendez-vous

- Les jours et plages horaires visibles par les clients doivent être administrables côté admin.
- L’admin doit voir d’abord la liste des jours de la semaine, puis entrer dans une journée pour gérer ses plages horaires.
- L’admin doit pouvoir masquer ou afficher une journée complète quand des plages sont configurées.
- L'admin doit pouvoir ajouter, masquer, afficher ou supprimer les plages horaires.
- L'admin doit pouvoir filtrer les rendez-vous par statut et modifier le statut d'un rendez-vous client.
- Les statuts de rendez-vous doivent rester centralisés dans le modèle métier.
- Un rendez-vous actif passé doit pouvoir basculer automatiquement en `client pas présenté` après un délai administrable en minutes.
- Le délai par défaut est de 60 minutes, mais l'admin peut le modifier, par exemple 20 ou 3 minutes.
- La synchronisation des rendez-vous expirés doit utiliser le service métier commun et rester disponible via une commande Symfony dédiée.
- Le client doit pouvoir prendre un rendez-vous avec son adresse email, son appareil, son problème, son téléphone et son créneau.
- Deux comptes clients ne doivent jamais partager le même numéro de téléphone.
- Le client doit choisir son créneau avec un affichage visuel par jour, puis par horaires disponibles, sans liste déroulante de créneaux.
- La prise de rendez-vous client doit se faire en étapes avec JavaScript : informations de réparation et téléphone, coordonnées client, puis choix du jour et du créneau.
- Si l'adresse email ne correspond à aucun compte, le système crée automatiquement un compte client avec un mot de passe temporaire.
- Le mot de passe temporaire doit être affiché dans l'espace client uniquement au moment utile, puis le client doit être invité à le changer.
- Si l'adresse email existe déjà et que le client n'est pas connecté, le système ne doit pas donner accès automatiquement au compte existant.
- Le client doit pouvoir consulter ses rendez-vous, annuler un rendez-vous actif et déplacer sa date vers un créneau disponible.

## 8. Qualité et vérification

Quand c'est pertinent, Codex vérifie les changements avec une ou plusieurs commandes adaptées :

- `composer validate`
- `php bin/console lint:twig templates`
- `php bin/console lint:container`
- `php bin/console debug:router`
- `php bin/phpunit`

Si une commande échoue, Codex corrige quand c'est dans le périmètre de la demande, sinon il explique le problème.

## 9. Gestion des fichiers

- Les fichiers générés ou temporaires ne doivent pas être ajoutés au projet sauf besoin explicite.
- Les fichiers contenant du texte en français doivent rester encodés en UTF-8 pour éviter les caractères accentués corrompus.
- Les assets doivent rester organisés dans `assets` ou `public` selon leur usage.
- Les templates doivent rester simples, lisibles et factorisés seulement quand cela apporte une vraie clarté.
- Les commentaires dans le code doivent être rares et utiles.

## 10. Livraison d'une intervention

A la fin d'une intervention, Codex doit indiquer :

- les fichiers modifiés ou créés ;
- les validations exécutées ;
- les points restant à arbitrer, s'il y en a.

## 11. Points à compléter par le propriétaire du projet

Ces choix pourront être ajoutés au contrat plus tard :

- nom public exact de la clinique ;
- charte graphique, logo, couleurs et typographies ;
- pages prioritaires ;
- services médicaux à présenter ;
- parcours de prise de rendez-vous ;
- langues à supporter ;
- contraintes légales ou RGPD ;
- hébergement cible.
