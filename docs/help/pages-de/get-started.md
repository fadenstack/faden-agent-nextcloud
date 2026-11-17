# Erste Schritte {#get-started}

Um den Agenten zu nutzen, installiert Ihr Nextcloud-Administrator die App und verbindet sie mit dem
Fadenstack-Server Ihrer Organisation, und der Fadenstack-Administrator gibt Ihnen Zugriff auf den Agenten. Dann
öffnen Sie Dateien und melden sich einmal pro Besuch an.

<!-- picture: start/top-bar.png — the Files top bar with the Fadenstack button that opens the agent -->

## Was Sie brauchen {#what-you-need}

- Ein Nextcloud-Konto auf einem Nextcloud-Server der Version 31 bis 35, auf dem der Administrator die App
  installiert und aktiviert hat.
- Ein Fadenstack-Konto auf dem Fadenstack-Server Ihrer Organisation, mit Zugriff auf den Agenten, den Ihr
  Administrator verbunden hat. Den Zugriff gibt der Administrator in der Fadenstack-Konsole (siehe
  [Agenten und Add-ins](../../administer/agents.md)).
- Einen aktuellen Browser. Der Agent läuft in der Nextcloud-Seite; auf Ihrem Computer wird nichts installiert.

## Den Agenten öffnen {#open-the-agent}

1. Öffnen Sie **Dateien** in Nextcloud.
2. Wählen Sie die Fadenstack-Schaltfläche in der oberen Leiste.

Das Panel öffnet sich rechts. Bei jedem Besuch fragt es beim ersten Mal nach der Anmeldung: siehe
[Anmelden](sign-in.md).

Sie können es auch von einer Datei aus öffnen: Öffnen Sie das Menü **Aktionen** einer Datei und wählen Sie
**Fadenstack fragen**. Die Datei ist dann schon für Ihre nächste Nachricht aufgenommen. Im Vollbild-Editor Text
öffnet die Schaltfläche in der Titelleiste dasselbe Panel.

## Die Lizenz {#the-licence}

Die App ist freie Software unter der GNU Affero General Public License, Version 3 oder neuer (AGPL-3.0-or-later).
Sie können sie nutzen, ohne dafür zu bezahlen. Der Fadenstack-Server Ihrer Organisation und die Modelle, die er
anbietet, gehören Ihrer Organisation.

## Für Administratoren: die App installieren und verbinden {#install}

Der Nextcloud-Administrator tut das einmal für alle.

1. Öffnen Sie in Nextcloud **Apps**, suchen Sie **Fadenstack Agent** und wählen Sie **Herunterladen und
   aktivieren**. Während der Vorschau liegt die App im Beta-Kanal des App Stores.
2. Öffnen Sie **Verwaltungseinstellungen**, dann **Zusätzliche Einstellungen**, und suchen Sie **Fadenstack Agent**.
3. Schalten Sie **Integration aktivieren** ein.
4. Tragen Sie bei **Fadenstack-Serveradresse** die Adresse Ihres Fadenstack-Servers ein, z. B.
   `https://ai.example.internal`. Sie muss mit `https://` beginnen.
5. Tragen Sie bei **Kennung des veröffentlichten Agenten** den Agenten ein, den der Fadenstack-Administrator für
   Nextcloud veröffentlicht hat.
6. Wählen Sie **Speichern**. Die Benutzer laden Dateien neu, um die neuen Einstellungen zu verwenden.

<!-- picture: start/admin-settings.png — Additional settings with the Fadenstack Agent form: Enable integration, Fadenstack server origin, Published agent identifier, Save -->

Auf dem Fadenstack-Server veröffentlicht der Administrator den Agenten, gibt den Benutzern Zugriff darauf und
erlaubt der Nextcloud-Adresse, den Server aus dem Browser aufzurufen.

??? info "Mehr zu den Einstellungen des Administrators"
    - Der Zugriff jedes Benutzers auf Nextcloud kommt immer aus seiner eigenen Nextcloud-Sitzung. Die App speichert
      kein Administratorpasswort und kein gemeinsames Konto.
    - **Experimentell: Entwicklungsmodus** ist standardmäßig aus. Er dient nur zum Testen, nie für den
      Produktivbetrieb: Er schaltet Tools für andere Nextcloud-Apps frei (darunter Mail und Talk, die Inhalte an
      andere Personen senden) und für externe Workflow-Dienste. Siehe die
      [Gebrauchsanweisung](more-help.md#instructions-for-use).
    - Wird die App deaktiviert oder entfernt, löscht sie ihre eigenen Aufzeichnungen der Änderungen des Agenten
      (siehe [Ihre Daten](your-data.md)). Die Einstellungen der Organisation bleiben.

## Wenn es nicht funktioniert {#when-it-does-not-work}

| Sie sehen | Was zu tun ist |
|---|---|
| **Bitten Sie Ihren Administrator, Fadenstack Agent unter Zusätzliche Einstellungen einzurichten und zu aktivieren.** | Die App ist installiert, aber noch nicht verbunden. Ihr Nextcloud-Administrator führt die obigen Schritte aus |
| Keine Fadenstack-Schaltfläche in der oberen Leiste | Die App ist für Sie nicht aktiviert. Fragen Sie Ihren Nextcloud-Administrator |
| **Verbindung nicht verfügbar** | Nextcloud erreicht den Fadenstack-Server nicht. Versuchen Sie es später noch einmal; bleibt es so, sagen Sie Ihrem Administrator Bescheid |

Mehr unter [Wenn es nicht funktioniert](troubleshooting.md).
