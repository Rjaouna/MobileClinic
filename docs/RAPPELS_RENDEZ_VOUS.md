# Rappels administratifs des rendez-vous

Le système crée des notifications administrateur sans modifier automatiquement le statut du rendez-vous.

## Commande de production

Planifier cette commande toutes les minutes :

```bash
php bin/console app:appointments:send-reminders
```

L’ancienne commande reste compatible et appelle la même logique :

```bash
php bin/console app:appointments:sync-expired
```

## Règles

- Avant le délai du premier rappel, aucune notification n’est créée.
- À partir de `first_reminder_delay_minutes`, un rappel `APPOINTMENT_DUE` est actif si le rendez-vous est encore `En attente`.
- À partir de `priority_reminder_delay_minutes`, le même rappel est renforcé en `APPOINTMENT_OVERDUE`.
- Une notification est résolue dès que le rendez-vous n’est plus `En attente` ou lorsqu’il est reporté dans le futur.
- Les notifications ne créditent ni n’annulent jamais la fidélité directement. La fidélité reste gérée par le changement de statut.

Les réglages se modifient depuis `/admin/rendez-vous`.
