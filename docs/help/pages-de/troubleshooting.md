# Wenn es nicht funktioniert {#when-it-does-not-work}

Die Meldungen, die Sie im Panel des Agenten sehen können, was sie bedeuten und was zu tun ist. Wurde eine Änderung
abgelehnt oder gestoppt, wurde nichts gespeichert, außer der Chat sagt **Gespeichert:**.

## Anmelden und Starten {#signing-in-and-starting}

| Sie sehen | Was zu tun ist |
|---|---|
| **Bitten Sie Ihren Administrator, Fadenstack Agent unter Zusätzliche Einstellungen einzurichten und zu aktivieren.** | Die App ist noch nicht mit einem Fadenstack-Server verbunden. Ihr Nextcloud-Administrator führt [die Einrichtung](get-started.md#install) durch |
| **Verbindung nicht verfügbar** | Nextcloud erreicht den Fadenstack-Server Ihrer Organisation nicht. Versuchen Sie es später noch einmal; bleibt es so, sagen Sie Ihrem Administrator Bescheid |
| **Ihre Anmeldung ist abgelaufen. Melden Sie sich erneut an.** | Melden Sie sich erneut an: siehe [Anmelden](sign-in.md) |
| Der Code ist abgelaufen, bevor Sie ihn genehmigt haben | Wählen Sie **Abbrechen**, dann **Mit einem Code anmelden** für einen neuen |
| **Ihre Organisation hat diesen Agenten ausgeschaltet.** | Ihre Organisation hat den Agenten ausgeschaltet. Ihr eingetippter Text bleibt. Wählen Sie später **Erneut prüfen** oder fragen Sie Ihren Administrator |

## Dateien und Kontext {#files-and-context}

| Sie sehen | Was zu tun ist |
|---|---|
| **The selected file changed. Select and include it again.** | Die Datei oder der Ordner hat sich geändert, nachdem Sie sie aufgenommen haben, z. B. weil jemand sie gespeichert hat. Wählen Sie sie unter Dateien erneut aus und senden Sie dann |
| **This file context expired. Select and include the file again.** | Eine aufgenommene Datei bleibt eine Stunde lang gültig. Wählen Sie sie erneut aus und senden Sie dann |
| **Nur das gespeicherte Dokument ist verfügbar. Speichern Sie Ihre Änderungen, bevor Sie eine Nachricht senden.** | Der Agent liest die gespeicherte Datei. Speichern Sie Ihre Änderungen und senden Sie dann |
| **Das aktive Dokument hat sich geändert. Öffnen Sie den Assistenten erneut, um es anzuhängen.** | Sie haben im Vollbild-Editor das Dokument gewechselt. Schließen Sie das Panel und öffnen Sie es erneut aus dem Editor |
| **Die indizierte Dokumentensuche ist auf diesem Server nicht verfügbar. Suchen Sie nach Dateinamen oder in einem angehängten Dokument.** | Ihre Nextcloud hat keine Volltextsuche für Dateien. Suchen Sie nach dem Namen oder nehmen Sie ein Dokument auf und fragen Sie dazu |
| Der Agent sagt, er kann eine Datei nicht lesen | Er liest Text, Markdown, CSV, JSON und PDFs mit Text, bis zu den Größen unter [Grenzen](what-it-can-do.md#limits). Speichern Sie eine Kopie in einem dieser Formate |

## Änderungen {#changes}

| Sie sehen | Was zu tun ist |
|---|---|
| Die Karte ist weg oder sagt, sie sei abgelaufen | Eine Karte wartet bis zu zehn Minuten. Fragen Sie erneut nach einer neuen Karte |
| **Die Ansicht der App wurde nicht fertig geladen. Es wurde nichts geändert. Versuchen Sie es erneut.** | Die Seite von Nextcloud war nicht bereit. Es wurde nichts gespeichert. Fragen Sie erneut |
| **In Nextcloud gespeichert. Aktualisieren Sie die Ansicht, nachdem Sie Ihre Änderungen gespeichert oder verworfen haben.** | Die Datei wurde gespeichert, während Sie sie mit ungespeicherten Änderungen geöffnet hatten. Speichern oder verwerfen Sie Ihre Änderungen und laden Sie dann die Ansicht neu |
| **In Nextcloud gespeichert. Öffnen Sie die geänderte Ressource, um sie anzuzeigen.** | Die Datei wurde gespeichert. Öffnen Sie sie, um die Änderung zu sehen |
| **Nextcloud confirmed the file save. Start a new conversation; …** | Die Datei wurde gespeichert, aber der Chat kann nicht sicher weitergehen. Wählen Sie **Neuer Chat** |
| **Nextcloud confirmed the Files action. Start a new conversation; …** | Dasselbe, nach einer Aktion an Dateien. Wählen Sie **Neuer Chat** |
| **Ohne KI-Kennzeichnung gespeichert:** und ein Grund | Die Datei wurde gespeichert, aber nicht gekennzeichnet: siehe [KI-Kennzeichnung](ai-marks.md) |
| **Undo file change** wird nicht angeboten | Rückgängigmachen braucht den Verlauf des Agenten (Ihr Administrator schaltet ihn ein), klappt bis zu 7 Tage nach der Änderung und nur, solange niemand die Datei seither bearbeitet hat. Nutzen Sie stattdessen die **Versionen** der Datei in Nextcloud |

## Antworten {#answers}

| Sie sehen | Was zu tun ist |
|---|---|
| Die Antwort dauert lange | Warten Sie, oder wählen Sie **Stoppen** (oder drücken Sie Escape) und stellen Sie eine engere Frage |
| **Nicht kopiert: Diese Antwort konnte nicht als KI-generiert gekennzeichnet werden, und Ihre Organisation verlangt das.** | Versuchen Sie es später noch einmal. Bleibt es so, sagen Sie Ihrem Administrator Bescheid |
| **Diese Antwort trägt kein maschinenlesbares KI-Kennzeichen. Ihre Organisation hat diesem Modell erlaubt, ohne eines zu antworten.** | Das ist die Entscheidung Ihrer Organisation für dieses Modell. Behandeln Sie die Antwort trotzdem als KI-generiert |
| Ein Fehler vom Server Ihrer Organisation statt einer Antwort | Versuchen Sie es erneut. Bleibt es so, sagen Sie Ihrem Administrator Bescheid, mit der Uhrzeit, zu der es passiert ist |

Kommen Sie nicht weiter? Siehe [Weitere Hilfe](more-help.md).
