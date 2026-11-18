# Produktinformationen {#product-information}

Wofür der Nextcloud-Agent da ist und wofür nicht, seine Grenzen und bekannten Risiken, was protokolliert wird, welche
Einstellungen Ihr Nextcloud-Administrator festlegen kann, wie lange er Sicherheitsupdates bekommt und wo Sie ein
Sicherheitsproblem melden. Wie Sie ihn nutzen, steht auf den anderen Seiten dieser Hilfe.

## Wofür er da ist {#what-it-is-for}

Der Nextcloud-Agent ist ein Assistent in Nextcloud Dateien. Er findet und liest Ihre eigenen Dateien, beantwortet Fragen
dazu und legt, wenn Sie jede Änderung freigeben, Textdateien an oder ersetzt sie. Er ist für Erwachsene, für Büroarbeit,
genutzt von einer Person zur Zeit. Diese Version ist eine Vorschau. Er arbeitet mit dem Fadenstack-Server Ihrer
Organisation, den Ihr Nextcloud-Administrator verbindet; einen Modus „eigene KI“ gibt es nicht, und Fadenstack betreibt
keinen Server für Sie. Die App ist freie Software unter der AGPL.

**Sie behalten die Kontrolle.** Der Agent arbeitet auf Ihre Anweisung und fragt, bevor er etwas ändert. KI macht Fehler:
Sie kann ein Dokument falsch lesen, irreführenden Inhalten folgen oder etwas Falsches schreiben. Prüfen Sie, was er
schreibt und tut, bevor Sie sich darauf verlassen, vor allem bevor etwas gesendet, veröffentlicht, gelöscht oder bezahlt
wird. Lassen Sie ihn keine wichtigen rechtlichen, medizinischen, finanziellen oder sicherheitsrelevanten Entscheidungen
allein treffen, und nutzen Sie seine Ausgabe nie als einzige Grundlage für eine Entscheidung über eine Person.

**Nicht für Hochrisiko-Zwecke.** Der Agent ist nicht für die Zwecke gemacht, die die KI-Verordnung der EU als
Hochrisiko einstuft (Anhang III), und er darf nicht zu einem Hochrisiko-KI-System umgebaut oder als solches genutzt
werden. Nutzen Sie ihn nicht:

- um Personen anhand biometrischer Daten zu identifizieren oder einzuordnen oder um Emotionen zu erkennen;
- als Sicherheitsbauteil kritischer Infrastruktur, etwa für Verkehr, Wasser, Gas, Wärme oder Strom;
- in der Bildung, um über die Zulassung zu entscheiden, Lernergebnisse zu bewerten, Personen einer Bildungsstufe
  zuzuordnen oder Prüfungen auf Täuschung zu überwachen;
- im Beruf, um Bewerbungen auszuwählen oder zu ranken, über Beförderung, Kündigung oder andere Arbeitsbedingungen zu
  entscheiden, Aufgaben nach Verhalten oder Eigenschaften zuzuteilen oder zu überwachen, wie Menschen arbeiten oder sich
  verhalten;
- um über öffentliche Leistungen oder Dienste zu entscheiden, die Kreditwürdigkeit zu bewerten, Lebens- oder
  Krankenversicherungen zu bepreisen oder Notrufe einzustufen;
- für Strafverfolgung, Migration, Asyl oder Grenzkontrolle;
- um Gerichten oder Behörden zu helfen, Recht zu ermitteln oder anzuwenden, oder um Wahlen oder das Wahlverhalten zu
  beeinflussen.

Das ist eine Information zum bestimmungsgemäßen Gebrauch, keine Lizenzbedingung. Wer den Agenten für einen solchen
Zweck nutzt, nutzt ihn außerhalb seiner Zweckbestimmung und kann zum Anbieter eines Hochrisiko-KI-Systems werden, mit den
Pflichten, die dazugehören. Den vollständigen Text finden Sie unter
[Datenschutzerklärung und Bedingungen](https://fadenstack.com/de/legal/nextcloud/).

## Grenzen {#limits}

- Er arbeitet mit Ihren eigenen Dateien und Ihren Nextcloud-Rechten: Was Sie nicht öffnen können, kann er auch nicht
  öffnen. In der Standardeinrichtung schreibt er nur in Ihre eigenen Dateien und gibt nichts an andere frei. Was er lesen
  kann und wie groß: siehe [Was er kann](what-it-can-do.md).
- Er schreibt nur Textdateien (`.txt` und `.md`). Jede Änderung fragt zuerst, mit dem ganzen Text auf der Karte, was auch
  immer der Tool-Modus (**Tool-Modus**) sagt: siehe [Fragen und freigeben](ask-and-approve.md).
- Eine Datei, die Sie auswählen, geht nur, wenn Sie sie aufnehmen und Ihre Nachricht senden; der Chip zeigt **Nächste
  Nachricht**, oder **Nicht enthalten**, wenn Sie die Datei herausgenommen haben.
- Ihr Browser erreicht nur den Fadenstack-Server Ihrer Organisation.
- Er hört auf, wenn Sie **Stoppen** wählen. Ihre Chats und Freigaben liegen nur im Arbeitsspeicher der Seite und sind
  weg, wenn Sie sie schließen oder neu laden.
- Was er schreibt, trägt eine KI-Kennzeichnung: einen signierten Nachweis in Nextcloud und, in einer Markdown-Datei, zwei
  Zeilen am Anfang. Eine Datei, die keine Kennzeichnung braucht (eine Übersetzung, eine Korrektur, eine kurze
  Bezeichnung), wird ohne gespeichert; der Chat sagt das nur, wenn Sie **Hinweis, wenn eine Antwort kein KI-Kennzeichen
  trägt** eingeschaltet haben, mit der Zeile **Die Kennzeichnungsrichtlinie des Agenten erforderte keine neue
  KI-Kennzeichnung.** Ob eine Kennzeichnung das Einfügen übersteht, hängt vom Programm ab: siehe
  [KI-Kennzeichnung](ai-marks.md).

## Bekannte Risiken {#known-risks}

- Eine Datei von anderen kann in ihrem Text Anweisungen verstecken. Der Agent behandelt Dateitext als Inhalt, kann aber
  trotzdem in die Irre geführt werden. Lesen Sie jede Freigabekarte, bevor Sie zustimmen.
- Was der Agent liest, geht an den Server Ihrer Organisation und weiter an das Modell. Siehe
  [Ihre Daten](your-data.md).
- Für das Rückgängigmachen und als Nachweis speichert Nextcloud den Text jeder Datei vor und nach jeder Änderung, die Sie
  freigeben, verschlüsselt, bis zu 7 Tage.
- Die experimentelle Einstellung (**Experimentell: Entwicklungsmodus**) ist nur zum Testen da, nie für den
  Produktivbetrieb. Sie entsperrt Tools für andere Nextcloud-Apps (Mail, Talk, Kalender, Kontakte, Deck, Tables und
  andere) und für externe Workflow-Dienste (n8n, Windmill). Manche senden Mails und Nachrichten an andere Menschen oder
  rufen externe Systeme auf, und sie sind ungetestet. Ihr eigenes Cookie wird an die eigene Adresse von Nextcloud
  weitergereicht, und Ihr Schlüssel für einen externen Dienst geht an diesen Dienst. Ein Administrator kann dann auch
  die KI-Kennzeichnung von Dateien abschalten: Der Agent schreibt Dateien ohne Kennzeichnung, und die Freigabekarte
  zeigt nicht, ob die Kennzeichnung an ist. Veröffentlichen Sie eine solche Datei nicht, als trüge sie eine. Wenn Sie
  einen solchen Text veröffentlichen, um die Öffentlichkeit über Angelegenheiten von öffentlichem Interesse zu
  informieren, müssen Sie ihn selbst als mit KI erstellt kennzeichnen.
- Das eigene Protokoll des Agenten über Tool-Aufrufe liegt nur im Arbeitsspeicher der Seite und lässt sich nicht
  exportieren; die dauerhaften Aufzeichnungen sind der Server Ihrer Organisation und Nextcloud (unten).

## Was protokolliert wird {#what-is-logged}

- **Auf dem Server Ihrer Organisation** meldet die App nach Tool-Läufen und Freigaben: welches Tool lief, wie lange es
  dauerte und wie es endete, Ihre Freigabeentscheidungen und Statistiken je Chat. Nie, was gelesen oder geschrieben
  wurde. Weder Sie noch Ihr Administrator können diese Meldungen ausschalten. Beim Start meldet sie außerdem eine kurze
  Beschreibung Ihres Betriebssystems und die Version der App; der Server behält die letzte Meldung je Gerät und entfernt
  ein Gerät, von dem er 90 Tage nichts gehört hat.
- Der Server erhält auch, was an das Modell geht (Ihre Nachrichten, die Angaben und der Text der aufgenommenen Datei und
  was die Tools lesen) und, bevor jedes Tool läuft, die ganze Eingabe dieses Tools: den zu schreibenden Text, die
  Suchwörter. Auch diese Prüfung lässt sich nicht abschalten.
- Ein Hashwert jeder Datei, die der Agent schreibt, und jeder Antwort, die Sie kopieren, geht an den Server, damit er die
  KI-Kennzeichnung signieren kann. Nie der Text.
- **In Nextcloud** hält ein Journal für jede Änderung, die Sie freigeben, den Text der Datei vor und nach der Änderung
  verschlüsselt 7 Tage lang fest, den Beleg der Änderung ohne Text 30 weitere Tage. Eine Änderung mit unklarem Ergebnis
  bleibt, bis sie geprüft wird. Das geschieht, ob der Verlauf an ist oder nicht. Das Log von Nextcloud enthält keine
  Inhalte.
- **In diesem Browser** werden nur Ihre Sprache und eine Anzeigeeinstellung aufbewahrt.
- **Was das Journal löscht:** Löschen Sie Ihr Nextcloud-Konto, werden Ihre Einträge sofort gelöscht (schlägt das fehl,
  laufen sie wie oben ab); wird die App deaktiviert oder entfernt, werden das ganze Journal, alle offenen Freigaben und
  die gespeicherten Schlüssel für externe Workflow-Dienste gelöscht, außer der Administrator entfernt die App mit
  `occ app:remove --keep-data`. Dateien, die der Agent geschrieben hat, bleiben mit ihrer Kennzeichnung in Ihren
  Dateien.
- Inhalte werden in kein Protokoll geschrieben, und einen Schalter dafür gibt es nicht. Das Übrige und wie Sie einen Chat
  exportieren, steht unter [Ihre Daten](your-data.md).

## Einstellungen, die Ihr Administrator festlegen kann {#settings-your-administrator-can-fix}

Ihr Nextcloud-Administrator setzt sie in der Nextcloud-Verwaltung unter „Zusätzliche Einstellungen“, im Formular
**Fadenstack Agent**, und mit dem Befehl `occ` von Nextcloud (App-Werte). Eine Änderung gilt, wenn Personen Dateien neu
laden.

| Einstellung oder App-Wert | Was sie bewirkt | Ohne Angabe |
|---|---|---|
| **Integration aktivieren** | Schaltet den Agenten für alle ein | aus |
| **Fadenstack-Serveradresse** | Der Fadenstack-Server Ihrer Organisation, mit `https://`; die einzige Adresse, mit der die App spricht | keine |
| **Kennung des veröffentlichten Agenten** | Der Agent, den der Fadenstack-Administrator für Nextcloud veröffentlicht hat | keine |
| `history_enabled` | Verlauf: Tools, um ein früheres Update einer Textdatei rückgängig zu machen, zu wiederholen oder zu prüfen, jeweils mit eigener Freigabe | aus |
| `files_reads_enabled` | Ordnerübersicht und Freigaben: listet den Baum eines Ordners und mit wem eine Datei geteilt ist | aus |
| `scope_policy` | Was der Agent erreichen darf und wie viel er in einem Durchgang tun darf | nur Ihre eigenen Dateien, private Ausgaben, 30 Aufrufe, 10 Änderungen und 256 KiB je Durchgang |
| **Experimentell: Entwicklungsmodus** | Nur zum Testen. Entsperrt Tools für andere Apps und externe Workflow-Dienste, jeweils mit eigenem App-Wert, und lässt einen Administrator die KI-Kennzeichnung abschalten (`file_provenance_enabled` auf `0`); erlaubt außerdem HTTP auf localhost | aus |

Nicht abschalten kann der Administrator: die Meldungen an den Server, die Prüfung durch den Server vor jedem Tool-Lauf
und, im Produktivbetrieb, die KI-Kennzeichnung. Wie lange das Journal aufbewahrt wird, ist fest. Eine Richtliniendatei
wie bei den anderen Agenten gibt es nicht.

## Sicherheitsupdates und Support {#security-updates-and-support}

Der Nextcloud-Agent bekommt Sicherheitsupdates mindestens bis zum **8. Dezember 2031**. Wir gehen jeder gemeldeten
Sicherheitslücke nach, beheben sie ohne unangemessene Verzögerung und sagen Ihnen Bescheid, wenn eine Lücke aktiv
ausgenutzt wird. Wir beheben Lücken nur in der neuesten Version; Ihr Nextcloud-Administrator aktualisiert die App. Halten
Sie auch Nextcloud aktuell.

- **Ein Sicherheitsproblem:** Melden Sie es vertraulich, wie es unter
  [Support und Kontakt](https://fadenstack.com/de/support/#security) und in unserer
  [security.txt](https://fadenstack.com/.well-known/security.txt) steht. Nie in einem öffentlichen Issue.
- **Hilfe und andere Fragen:** [Support und Kontakt](https://fadenstack.com/de/support/). Der Quellcode und der
  Issue-Tracker liegen unter [faden-agent-nextcloud](https://github.com/fadenstack/faden-agent-nextcloud).
- **Datenschutzerklärung und Bedingungen:** [fadenstack.com/de/legal/nextcloud](https://fadenstack.com/de/legal/nextcloud/).
