# Was er kann {#what-it-can-do}

Der Agent arbeitet in Ihren Nextcloud-Dateien. Er findet und liest Dateien, beantwortet Fragen dazu und erstellt
oder aktualisiert Textdateien, nachdem Sie jede Änderung erlaubt haben. Diese Seite listet seine Tools und seine
Grenzen auf.

## Seine Tools {#its-tools}

| Der Agent kann | Fragt vorher nach |
|---|---|
| Dateien und Ordner nach Name, Typ, Ordner oder Datum finden | Nein |
| Die Details einer gefundenen Datei oder der aufgenommenen Datei zeigen | Nein |
| Auflisten, was im aufgenommenen Ordner liegt | Nein |
| Die aufgenommene Datei oder eine gefundene Datei lesen | Nein |
| Textstellen in einem Dokument finden, mit Seite oder Zeile | Nein |
| Die Inhalte Ihrer Dateien durchsuchen, wo Nextcloud einen Volltextindex hat | Nein |
| Eine `.md`- oder `.txt`-Datei erstellen | **Ja, jedes Mal** |
| Den Text einer `.md`- oder `.txt`-Datei ersetzen | **Ja, jedes Mal** |

Tools, die nur lesen, laufen ohne Karte. Jede Änderung zeigt ihre eigene Karte mit dem ganzen Text: siehe
[Fragen und freigeben](ask-and-approve.md).

Ihr Nextcloud-Administrator kann außerdem mit dem Nextcloud-Befehl `occ` einschalten:

- Verlauf (`history_enabled`): Aktualisierungen einer Textdatei durch den Agenten rückgängig machen und wiederholen, jeweils mit
  eigener Freigabe (siehe [Eine Änderung rückgängig machen](ask-and-approve.md#undo)).
- Ordnerübersicht und Freigaben (`files_reads_enabled`): den Baum eines Ordners auflisten und zeigen, mit wem eine Datei geteilt ist.

## Woran er arbeitet {#what-it-works-on}

- Ihre eigenen Dateien: was Sie in Nextcloud öffnen können, einschließlich der Dateien, die andere mit Ihnen
  geteilt haben. Ihre Nextcloud-Berechtigungen gelten für jeden Schritt; der Agent kann nichts öffnen, was Sie
  nicht öffnen können.
- In der Standardeinrichtung schreibt er nur in Ihre eigenen Dateien und teilt nichts mit anderen.
- Er liest Text-, Markdown-, CSV- und JSON-Dateien und PDFs, die Text enthalten.

## Grenzen {#limits}

- **Eine Datei oder ein Ordner pro Nachricht.** Mehrere Dateien werden nicht zusammengeführt.
- **Formate, die er nicht lesen kann:** Word-, Excel- und PowerPoint-Dateien, gescannte Seiten ohne Text und
  PDFs mit Passwort. Manche PDFs liest er nur teilweise; die Antwort sagt es dann.
- **Größen:** Textdateien bis 2 MiB und PDFs bis 10 MiB; er liest bis zu 50.000 Zeichen und 50 Seiten einer Datei.
  Was er schreibt, ist bis zu 256 KiB groß.
- **Die Suche in Dateiinhalten** braucht die Volltextsuche von Nextcloud. Ohne sie sagt der Agent es; er sucht
  nicht stattdessen in Dateinamen, ohne es Ihnen zu sagen.
- **„Neueste“** heißt zuletzt geändert, nicht ein Datum, das im Dokument steht.
- **Pro Nachricht:** höchstens 30 Tool-Aufrufe und 10 Änderungen. Eine aufgenommene Datei bleibt eine Stunde lang
  gültig, und eine Karte wartet bis zu zehn Minuten.
- **Antworten können falsch sein.** Der Agent kann ein Dokument falsch lesen, und eine Datei von jemand anderem
  kann Text enthalten, der ihn in die Irre führen soll. Prüfen Sie die Textstellen bei allem, was wichtig ist, und
  lesen Sie jede Änderung, bevor Sie sie erlauben.

??? info "Mehr zur experimentellen Einstellung"
    Ihr Administrator kann **Experimentell: Entwicklungsmodus** zum Testen einschalten. Mit weiteren Einstellungen
    lässt er den Agenten andere Nextcloud-Apps nutzen (etwa Kalender, Kontakte, Deck, Tables, Mail und Talk),
    Ordner erstellen, Dateien kopieren, verschieben und teilen und externe Workflow-Dienste aufrufen. Diese Tools
    sind ungetestet, können sich ändern, und manche senden Inhalte an andere Personen. Er kann auch die
    KI-Kennzeichnung von Dateien ausschalten. Er ist nicht für die
    tägliche Arbeit gedacht. Die [Gebrauchsanweisung](more-help.md#instructions-for-use) beschreibt ihn.
