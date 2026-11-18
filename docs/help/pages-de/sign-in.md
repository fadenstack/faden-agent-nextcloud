# Anmelden {#sign-in}

Das Panel meldet sich bei Ihrem Fadenstack-Konto auf dem Server Ihrer Organisation an. Melden Sie sich mit einem
Code an: Sie genehmigen ihn auf dem Fadenstack-Server, sodass Ihr Fadenstack-Passwort nie in Nextcloud eingetippt
wird.

![Das Panel vor der Anmeldung: Bei Fadenstack anmelden, der Server, Mit einem Code anmelden, E-Mail und Passwort](images/sign-in/panel.png)

## Bevor Sie beginnen {#before-you-start}

- Sie sind bei Nextcloud angemeldet und haben den Agenten geöffnet (siehe [Erste Schritte](get-started.md)).
- Sie haben ein Fadenstack-Konto mit Zugriff auf den Agenten. Wenn nicht, fragen Sie Ihren Administrator.

## Mit einem Code anmelden {#sign-in-with-a-code}

1. Wählen Sie im Panel **Mit einem Code anmelden**.
2. Das Panel zeigt einen Code. Wählen Sie **Anmeldeseite öffnen**. Die Fadenstack-Seite **Eine App anmelden** öffnet
   sich in einem neuen Tab, der Code ist schon eingetragen.
3. Ist dieser Tab noch nicht bei Fadenstack angemeldet, melden Sie sich wie gewohnt an: mit Ihrem Passwort oder mit
   der Anmeldung Ihrer Organisation.
4. Prüfen Sie, ob der **Code** der ist, den das Panel zeigt, und wählen Sie **Weiter**.
5. Prüfen Sie, ob die anfragende App **Nextcloud Files** ist, und wählen Sie dann **Genehmigen**.

![Das Panel mit einem Anmeldecode, Anmeldeseite öffnen, Warten auf Bestätigung, der Ablaufzeit des Codes und Abbrechen](images/sign-in/code.png)

Gehen Sie zurück zu Nextcloud. Das Panel schließt die Anmeldung selbst ab und zeigt den Chat.

??? info "Mehr zur Anmeldung mit einem Code"
    - Der Code gilt einmal und läuft zu der Zeit ab, die das Panel zeigt (**Der Code läuft um … ab.**). Um neu zu
      beginnen, wählen Sie **Abbrechen**, dann **Mit einem Code anmelden**.
    - Die Anmeldeseite zu öffnen genehmigt nichts; das tut nur **Genehmigen**. Haben Sie die Anmeldung nicht
      gestartet, wählen Sie **Ablehnen**.
    - Was die Genehmigungsseite zeigt und wie Sie Ihre Anmeldungen später sehen und beenden:
      [Eine Desktop-App anmelden](../../use/sign-in-an-app.md).

## Mit E-Mail und Passwort anmelden {#sign-in-with-your-email-and-password}

Sie können auch Ihre Fadenstack-E-Mail-Adresse und Ihr Passwort in **Benutzername oder E-Mail** und
**Passwort** eintippen und dann **Anmelden** wählen. Beide gehen nur an Ihren
Fadenstack-Server; Nextcloud speichert sie nicht.

## Wie lange Sie angemeldet bleiben {#how-long-you-stay-signed-in}

Die Anmeldung gilt, solange die Nextcloud-Seite geöffnet ist, und erneuert sich während Ihrer Arbeit. Laden Sie die
Seite neu oder öffnen Sie Nextcloud in einem anderen Tab, melden Sie sich erneut an.

Zum Abmelden öffnen Sie die Einstellungen (das Zahnrad in der Kopfzeile des Panels) und wählen **Abmelden**.

## Wenn es nicht funktioniert {#when-it-does-not-work}

| Sie sehen | Was zu tun ist |
|---|---|
| **Ihre Anmeldung ist abgelaufen. Melden Sie sich erneut an.** | Melden Sie sich erneut an |
| Der Code ist abgelaufen, bevor Sie ihn genehmigt haben | Wählen Sie **Abbrechen**, dann **Mit einem Code anmelden** für einen neuen Code |
| **Ihre Organisation hat diesen Agenten ausgeschaltet.** | Ihre Organisation hat den Agenten auf dem Fadenstack-Server ausgeschaltet. Ihr eingetippter Text bleibt erhalten; wählen Sie später **Erneut prüfen** oder fragen Sie Ihren Administrator |
| Die Anmeldeseite markiert den Agenten als für Sie nicht verfügbar oder lässt das Genehmigen nicht zu | Sie haben noch keinen Zugriff auf den Agenten. Fragen Sie Ihren Administrator |
| **Verbindung nicht verfügbar** in **Details zum Assistenten** | Die Adresse des Fadenstack-Servers in den Einstellungen von Nextcloud ist nicht gültig. Fragen Sie Ihren Nextcloud-Administrator |
