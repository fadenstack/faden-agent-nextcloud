# Fragen und freigeben {#ask-and-approve}

Nehmen Sie eine Datei oder einen Ordner auf, fragen Sie in Ihren eigenen Worten und prüfen Sie jede Änderung, bevor
der Agent sie vornimmt. In Nextcloud wird nichts gespeichert, bis Sie **Einmal erlauben** wählen, und Sie können den
Agenten jederzeit stoppen.

<!-- picture: ask/panel.png — the panel with a file included (the chip above the message box), a question and an answer -->

## Eine Datei oder einen Ordner aufnehmen {#include-a-file-or-folder}

Der Agent liest nur, was Sie ihm geben, und was seine Tools in Ihren Dateien finden, wenn Sie fragen.

1. Wählen Sie unter Dateien eine Datei oder einen Ordner aus (Kästchen anhaken) oder öffnen Sie sein Menü
   **Aktionen** und wählen Sie **Fadenstack fragen**.
2. Die Datei erscheint als Chip über dem Nachrichtenfeld, daneben steht **Nächste Nachricht**.

Gelesen wird sie erst, wenn Sie Ihre Nachricht senden. Um sie wegzulassen, wählen Sie den Chip: Dann steht dort
**Nicht enthalten**. Während eine Antwort geschrieben wird, steht in der Zeile **Diese Antwort**: Wählen Sie dann eine
andere Datei, bereitet das Ihre nächste Nachricht vor und ändert die laufende Antwort nicht.

??? info "Mehr zum Aufnehmen von Dateien"
    - Eine Datei oder ein Ordner pro Nachricht.
    - Der Agent liest die gespeicherte Datei, nicht das, was in einem Editor geöffnet und ungespeichert ist.
      Speichern Sie Ihre Änderungen zuerst.
    - Eine Suchergebniskarte hat **In Nextcloud öffnen**, um die Datei anzusehen, und **Als Kontext hinzufügen**, um
      sie aufzunehmen.
    - Was an den Server Ihrer Organisation geht, wenn Sie eine Datei aufnehmen: siehe
      [Ihre Daten](your-data.md).

## Fragen {#ask}

Tippen Sie Ihre Frage und wählen Sie **Senden**. Zum Beispiel:

- „Suche den neuesten Lieferantenvertrag.“
- „Suche alle PDFs im Ordner Engineering.“
- „Wie lang ist die Kündigungsfrist in diesem Vertrag?“
- „Schreibe eine Zusammenfassung dieses Dokuments als Markdown-Datei im selben Ordner.“

Der Agent findet und liest Dateien selbstständig: Diese Tools lesen nur und brauchen keine Freigabe. Beruht eine
Antwort auf einem Dokument, zeigt sie die Textstelle mit Seite oder Zeile und einen Link darauf. Prüfen Sie die
Textstellen bei allem, was wichtig ist.

## Eine Änderung freigeben {#approve-a-change}

Will der Agent eine Datei erstellen oder ändern, zeigt er zuerst eine Freigabekarte.

<!-- picture: ask/card.png — the approval card Create file: the target path, the proposed content with its front matter, Allow once and Deny -->

1. Lesen Sie die Karte: **Datei erstellen** oder **Datei ersetzen**, wohin sie kommt, und den ganzen Text unter
   **Vorgeschlagener Inhalt**. Bei einem Ersetzen zeigt **Aktueller Inhalt**, was jetzt dort steht.
2. Wählen Sie **Einmal erlauben**, um genau diesen Text zu speichern, oder **Ablehnen**, um nichts zu speichern.

Nach **Einmal erlauben** steht im Chat **Gespeichert:** und der Name der Datei, mit einer Zeile zu ihrer
KI-Kennzeichnung (siehe [KI-Kennzeichnung](ai-marks.md)).

??? info "Mehr zu den Freigaben"
    - Jede Änderung fragt nach, eine nach der anderen. In diesem Agenten gibt es für Änderungen kein „für diesen
      Chat erlauben“ und kein „immer erlauben“.
    - Erstellen überschreibt nie eine Datei, die es schon gibt. Ersetzen klappt nur, wenn sich die Datei seit dem
      Lesen durch den Agenten nicht geändert hat.
    - Eine Karte, die zu lange wartet, läuft ab; es wird nichts gespeichert. Fragen Sie erneut nach einer neuen
      Karte.
    - Ist eine Datei mit ungespeicherten Änderungen in einem Editor geöffnet, lässt Nextcloud Ihren Editor, wie er
      ist: Speichern oder verwerfen Sie Ihre Änderungen und aktualisieren Sie dann die Ansicht.

## Der Tool-Modus {#the-tool-mode}

Der Tool-Modus in der Kopfzeile des Panels legt fest, was der Agent ohne Nachfrage tun darf. Welche Modi Sie wählen
können, entscheidet Ihre Organisation.

| Modus | Was der Agent tut |
|---|---|
| **Aus** | Keine Tools: Der Agent antwortet nur |
| **Nur lesen** | Nur Tools, die nichts ändern |
| **Nachfragen** | Fragt, bevor etwas geändert wird (der übliche Modus) |
| **Automatisch** | Führt Tools aus, die etwas ändern; fragt weiterhin vor allem, was sich nicht rückgängig machen lässt |

In diesem Agenten fragt jede Änderung an einer Datei nach Ihrer Freigabe, in jedem Modus. Mehr unter
[Tools im Chat](../../use/tools.md).

## Stoppen {#stop}

- **Stoppen** im Nachrichtenfeld oder die Escape-Taste stoppt die Antwort, die gerade geschrieben wird. Auch das
  Schließen des Panels stoppt sie.
- Während der Agent arbeitet, zeigt eine Leiste **Assistent arbeitet…** mit **Chat ausblenden** und **Stoppen**.
  **Chat anzeigen** holt den Chat zurück.

Eine Änderung, die Sie schon erlaubt haben, kann gespeichert worden sein, bevor Sie gestoppt haben: Sehen Sie sich
die Datei an.

## Alles stoppen {#stop-everything}

**Alles stoppen** ist die rote Schaltfläche in der Kopfzeile des Panels. Ein Klick, ohne vorherige Rückfrage:

- stoppt jede Antwort, die gerade geschrieben wird,
- bricht jede Änderung ab, die auf Ihre Freigabe wartet, sodass nichts davon gespeichert wird,
- beendet jede zeitlich begrenzte Berechtigung, die der Agent auf dem Server Ihrer Organisation hat.

<!-- picture: ask/stop-everything.png — the panel header with Stop everything and its result line under the button -->

Eine kurze Zeile unter der Schaltfläche sagt, was gestoppt wurde. Nutzen Sie sie, wenn der Agent etwas tut, worum
Sie nicht gebeten haben.

## Eine Änderung rückgängig machen {#undo}

Hat Ihr Administrator den Verlauf des Agenten eingeschaltet, listet die Schaltfläche **Agent actions** in der
Kopfzeile auf, was der Agent geändert hat, und bietet bei der Aktualisierung einer Textdatei **Undo file change** und
**Redo file change** an. Jedes Rückgängigmachen oder Wiederholen zeigt eine eigene Karte und braucht
**Einmal erlauben**.

??? info "Mehr zum Rückgängigmachen"
    - Rückgängigmachen klappt bis zu 7 Tage nach der Änderung und nur, solange niemand die Datei seither bearbeitet
      hat.
    - Ist das Ergebnis einer Änderung unklar, bietet der Eintrag **Inspect outcome** an: Er vergleicht die Datei mit
      dem, was beabsichtigt war, und hält fest, was er findet, ohne die Datei zu ändern.
    - Ohne den Verlauf des Agenten nutzen Sie die Dateiversionen von Nextcloud (**Versionen** in den Details der
      Datei).
