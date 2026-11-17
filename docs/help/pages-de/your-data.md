# Ihre Daten {#your-data}

Was der Agent an den Fadenstack-Server Ihrer Organisation sendet, was er speichert, wo und wie lange. Die
Datenschutzerklärung Ihrer Organisation für Fadenstack gilt zusätzlich; fragen Sie Ihren Administrator danach.

## Was an den Server Ihrer Organisation geht {#what-goes-to-your-organisations-server}

Wenn Sie eine Nachricht senden, gehen diese Daten an den Fadenstack-Server Ihrer Organisation und weiter an das
Modell, das er verwendet:

- Ihre Nachricht;
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
  Aufzeichnung des Agenten dazu bleibt im Speicher der Seite und ist mit der Seite weg. Die dauerhaften
  Aufzeichnungen sind die des Servers (oben) und die von Nextcloud (unten).

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
- **Ihr Nextcloud-Konto wird gelöscht**: Nextcloud löscht Ihre Aufzeichnungen der Änderungen des Agenten sofort.
- **Die App wird von Ihrem Administrator deaktiviert oder entfernt**: Nextcloud löscht alle Aufzeichnungen der
  Änderungen des Agenten. Dateien, die der Agent geschrieben hat, bleiben in Ihren Dateien, mit ihrer
  KI-Kennzeichnung.
- **Ihr Fadenstack-Konto wird gelöscht**: Der Server löscht die Aufzeichnungen des Agenten zu Ihren Tool-Aufrufen
  und Ihren Geräten.

Die Dateien, die der Agent geschrieben hat, sind Ihre Dateien: Löschen Sie sie wie jede andere Datei.
