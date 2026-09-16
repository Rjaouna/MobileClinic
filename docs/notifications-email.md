# Notifications e-mail Mobile Clinic

Le service `CustomerNotificationMailer` centralise les messages envoyés à l'adresse du client concerné. L'adresse de notification du magasin sert d'expéditeur et de destinataire par défaut pour la commande de diagnostic, sans recevoir de copie des notifications client.

## Événements couverts

- création d'un compte, avec mot de passe temporaire uniquement pour le client concerné ;
- création, déplacement, annulation et changement de statut d'un rendez-vous ;
- crédit, débit, correction, validation ou annulation d'un mouvement fidélité ;
- création, validation, retrait, annulation et expiration d'une réservation boutique ;
- remboursement automatique de la cagnotte lié à une réservation boutique.

Chaque e-mail contient le logo configuré dans l'administration, l'adresse, le téléphone, les horaires et les liens utiles.

## Configuration

Les valeurs sensibles restent dans `.env.local`, ignoré par Git :

```dotenv
NOTIFICATION_ENABLED=1
MAILER_DSN=smtps://UTILISATEUR:MOT_DE_PASSE@SERVEUR:465
NOTIFICATION_FROM_EMAIL=adresse-envoi@example.com
NOTIFICATION_ADMIN_EMAIL=adresse-diagnostic@example.com
NOTIFICATION_SITE_URL=https://www.example.com
```

`NOTIFICATION_SITE_URL` doit être l'URL publique du site afin que les liens reçus par e-mail fonctionnent aussi depuis les commandes automatiques.

## Vérification

```bash
php bin/console app:notifications:test destinataire@example.com
```

Chaque remise acceptée par le serveur SMTP et chaque échec sont enregistrés dans `var/log/dev.log` ou `var/log/prod.log` sans bloquer l'action du client ou de l'administrateur.
