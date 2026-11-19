# Ihre Daten {#your-data}

Was der Agent an den Fadenstack-Server Ihrer Organisation sendet, was er speichert, wo und wie lange. Die
Datenschutzerklärung Ihrer Organisation für Fadenstack gilt zusätzlich; fragen Sie Ihren Administrator danach.

## Was an den Server Ihrer Organisation geht {#what-goes-to-your-organisations-server}

Wenn Sie eine Nachricht senden, gehen diese Daten an den Fadenstack-Server Ihrer Organisation und weiter an das
Modell, das er verwendet:

- Ihre Nachricht und der bisherige Chat;
- die Datei oder der Ordner, den Sie aufgenommen haben: Name und Details, bei einer Datei auch ihr Text;
- was die Tools des Agenten beim Antworten lesen: Dateinamen, Ordner, Textstellen und Listen.

Der Server sieht außerdem jeden Tool-Aufruf **bevor** er läuft, mit dem, was dem Tool übergeben wird (bei einer
Änderung der ganze Text, der geschrieben werden soll), damit die Regeln Ihrer Organisation ihn erlauben oder
ablehnen können.

??? info "Mehr dazu, was der Server aufzeichnet"
    - **Jeder Tool-Aufruf und jede Freigabe**: welches Tool, wann und was entschieden wurde. Nicht der Inhalt.
    - **Wenn der Agent startet**: der Name des Betriebssystems Ihres Browsers und die Version der App, für die
      Liste Ihrer Geräte in der Konsole.
    - **Die KI-Kennzeichnung einer Datei oder einer kopierten Antwort**: ein Fingerabdruck des Textes (ein Hash),
      nie der Text selbst.
    - Wie lange der Server das speichert und wer in Ihrer Organisation es sehen kann, legt Ihre Organisation fest:
      siehe [Ihre Daten und Datenschutz](../../use/privacy.md).

## Was in Ihrem Browser bleibt {#what-stays-in-your-browser}

- **Ihre Chats und Freigaben** bleiben im Speicher der Seite. Sie sind weg, wenn Sie die Seite schließen oder neu
  laden.
- **Die Einstellungen des Panels**, die Sie ändern, werden in Ihrem Browser gespeichert, nur für Ihr
  Nextcloud-Konto.
- **Die Aufzeichnung der Tool-Aufrufe**: Jeder Tool-Aufruf erscheint im Chat, mit dem, was entschieden wurde. Die
  Aufzeichnung des Agenten dazu bleibt im Speicher der Seite und ist mit der Seite weg; solange die Seite offen
  ist, können Sie sie als Webseite speichern ([unten](#your-data-in-this-browser)). Die dauerhaften
  Aufzeichnungen sind die des Servers (oben) und die von Nextcloud (unten).
- **Ein Protokoll der Arbeit des Agenten**: welche Anfragen er wann an den Server gestellt hat, welche Tools liefen,
  und Fehler, nie Ihre Nachrichten, die Antworten, Dateiinhalte oder was ein Tool bekommen hat. Es liegt in Ihrem
  Browser für Ihr Konto, mit höchstens 500 Einträgen; Einträge, die älter als 7 Tage sind, werden gelöscht, wenn
  der Agent das nächste Mal hineinschreibt. Die ersten Einträge nach dem Öffnen der Seite entstehen, bevor der
  Agent weiß, wer angemeldet ist; sie gehen in ein Protokoll dieses Browsers ohne Person. **Problem melden**
  kopiert die letzten Einträge des Protokolls für Ihre IT.

## Ihre Daten in diesem Browser {#your-data-in-this-browser}

Öffnen Sie die Einstellungen (das Zahnrad in der Kopfzeile des Panels) und wählen Sie **Ihre Daten in diesem
Browser**:

- **Meine Daten exportieren** speichert, was der Agent in diesem Browser für Sie aufbewahrt, als Datei.
- **Meine Daten entfernen…** löscht es nach einer Rückfrage: Ihr Protokoll und die Einstellungen des Panels.
- **Alle meine Fadenstack-Daten in diesem Browser entfernen…** löscht nach einer Rückfrage alle Fadenstack-Daten,
  die dieser Browser auf dieser Nextcloud für Sie aufbewahrt, auch das Protokoll aus der Zeit, bevor der Agent
  wusste, wer angemeldet ist. **Alle meine Fadenstack-Daten exportieren** speichert sie alle als Datei.
- **Protokoll der Tool-Aufrufe exportieren…** speichert die Aufzeichnung der Tool-Aufrufe als Webseite, solange
  die Seite offen ist. Eine Zeile darüber sagt, wie viele Einträge sie hat und ob sie unversehrt ist.

![Der Dialog Ihre Daten in diesem Browser: Meine Daten exportieren, Meine Daten entfernen…, Alle meine Fadenstack-Daten exportieren, Alle meine Fadenstack-Daten in diesem Browser entfernen…, die Zeile zur Aufzeichnung der Tool-Aufrufe mit ihren Einträgen und deren Export](images/data/your-data.png)

## Einen Chat exportieren {#export-a-chat}

1. Öffnen Sie die Einstellungen (das Zahnrad in der Kopfzeile des Panels).
2. Wählen Sie **Chat exportieren**.

Sie erhalten den Chat als Markdown-Datei, als KI-generiert gekennzeichnet. Verlangt Ihre Organisation diese
Kennzeichnung und lässt sie sich nicht anbringen, wird der Chat nicht gespeichert, und das Panel sagt es.

## Was Nextcloud speichert {#what-nextcloud-keeps}

Für jede Änderung, die Sie erlauben, speichert Nextcloud eine Aufzeichnung, damit die Änderung rückgängig gemacht
und geprüft werden kann:

| Was | Wie lange |
|---|---|
| Der Text der Datei vor und nach der Änderung, verschlüsselt | Bis zu 7 Tage |
| Die Aufzeichnung der Änderung, ohne den Text | 30 weitere Tage |
| Eine Änderung mit unklarem Ergebnis | Bis sie geprüft ist |

Diese Aufzeichnung wird gespeichert, ob Ihr Administrator den Verlauf des Agenten eingeschaltet hat oder nicht. Das
eigene Protokoll von Nextcloud enthält keine Inhalte Ihrer Dateien oder Chats.

## Ihre Daten entfernen {#remove-your-data}

- **Chats**: Schließen Sie die Seite oder laden Sie sie neu.
- **Das Protokoll und die Einstellungen des Panels**: **Meine Daten entfernen…** unter **Ihre Daten in diesem
  Browser** (oben). Einträge, die älter als 7 Tage sind, werden gelöscht, wenn der Agent das nächste Mal in das
  Protokoll schreibt. Das Protokoll aus der Zeit, bevor der Agent wusste, wer angemeldet ist, löscht nur
  **Alle meine Fadenstack-Daten in diesem Browser entfernen…**.
- **Ihr Nextcloud-Konto wird gelöscht**: Nextcloud löscht Ihre Aufzeichnungen der Änderungen des Agenten sofort.
  Schlägt das fehl, laufen sie ab, wie die Tabelle oben sagt.
- **Die App wird von Ihrem Administrator deaktiviert oder entfernt**: Nextcloud löscht alle Aufzeichnungen der
  Änderungen des Agenten und die Schlüssel, die Benutzer für externe Workflow-Dienste gespeichert haben, außer der
  Administrator entfernt die App und behält ihre Daten bewusst. Dateien, die der Agent geschrieben hat, bleiben in
  Ihren Dateien, mit ihrer KI-Kennzeichnung.
- **Ihr Fadenstack-Konto wird gelöscht**: Auf einem Fadenstack-Server ab Version 0.7.1 löscht der Server die
  Aufzeichnungen des Agenten zu Ihren Tool-Aufrufen und Ihren Geräten.

Die Dateien, die der Agent geschrieben hat, sind Ihre Dateien: Löschen Sie sie wie jede andere Datei.
