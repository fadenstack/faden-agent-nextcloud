# Search and document discovery

## English

While a response runs, the activity indicator has **Hide chat** and the same
**Stop** control as the chat. **Show chat** returns to the ongoing conversation.
A confirmed save can update a supported clean Files list automatically. An open
editor requires you to finish or cancel your edits; the assistant will not reload
that editor. **Saved** and **refresh required** are separate outcomes. Action
Undo/Redo controls are still under implementation.

Open Files and click the Fadenstack mascot in the top bar or app menu. Sign in
with your Fadenstack account. The assistant stays in the right sidebar. In the
full-screen Text viewer, the titlebar mascot opens the same assistant.

The drawer has one chat toolbar. **Assistant details** shows the configured
Fadenstack connection, published identifier and current saved-file metadata.
Opening it sends no request to the model and reads no file content.

The context row shows **Next message** before sending, **This response** while
working, or **Not included** if the attachment is excluded. Changing selection
during a response prepares the next message; the current response keeps its
original source. The row sits above the composer. **Stop** cancels the current
response; closing the drawer also stops it. Chat history contains conversations,
not an undo journal. **Agent actions** separately shows governed native attempts.
Undo/Redo appears only for independently certified private-text operations;
other native actions have an audit trail. See [Mail and Talk](mail-talk.md) and
[Collectives, Forms, Notes and Bookmarks](content-tools.md), and
[saved Whiteboard and personal Flow](whiteboard-flow.md) for their exact bounds.

Experimental [workflow engine connections and reads](workflow-engines.md) use
your own Windmill or n8n API token stored encrypted in Nextcloud. This slice
reads saved workflows and creates new private drafts after individual approval.
Bounded execution requires separate approval; OAuth, embedded editor behavior
and full release acceptance remain gated. See the engine guide for the bounds.

Experimental [agent-definition reads](agent-files.md) import an approved saved
`.agent` definition as data. Its native knowledge files need separate access;
imported instructions do not activate an agent or grant tools.

Ask naturally: “Find the latest supplier contract”, “Find all PDFs inside
Engineering”, or “Show my ten most recently modified documents”. Discovery
searches the filenames and metadata you can access, including shared files.
“Latest” means last modification, not the date printed inside a document.

Result cards show name, parent folder, modification date and a source link.
Choose **Open in Nextcloud** to inspect a file, or **Add to context** to attach
its metadata. Contents are read only when needed after sending. You can remove
or exclude the composer attachment. A Files checkbox also attaches one file;
multiple selections are not combined automatically.

Ask “What is the termination notice period?” or “Search this document for
quality assurance”. The agent can retrieve a discovered file or search literal
passages. PDF matches use actual extracted pages; text matches show line numbers.
An answer inferred from a filename is not document evidence: request the passage
and check its source link. Partial extraction makes the total match count unknown.

“Find documents mentioning ISO 27001” requires a supported native Nextcloud
full-text Files index. When it is absent, the assistant explains that limitation.
It does not silently search filenames instead or download everybody's documents.
TXT, Markdown, CSV, JSON and text PDFs are supported for local passage searches;
Office files, encrypted PDFs and image-only scans are not.

To save work, ask “Create a Markdown summary in the current folder”. Include the
folder deliberately, or have the agent identify a known destination. Inspect the
complete target and content on the approval card, then choose **Allow once** or
**Deny**. Creation never overwrites an existing file. Updates require a current
read and matching revision. Save editor changes before attaching: context comes
from the saved file, not the unsaved editor buffer.

## Deutsch

Öffne Dateien und klicke auf das Fadenstack-Maskottchen in der oberen Leiste oder
im App-Menü. Melde dich mit deinem Fadenstack-Konto an. Der Assistent bleibt in
der rechten Seitenleiste. Im Text-Editor im Vollbild öffnet das Maskottchen in
der Titelleiste denselben Assistenten.

**Assistentendetails** zeigt die Fadenstack-Verbindung, die veröffentlichte
Kennung und Metadaten der gespeicherten Datei. Das Öffnen liest keine Dateiinhalte.
Die Kontextzeile unterscheidet die nächste Nachricht, die laufende Antwort und
ausgeschlossene Anhänge. Eine neue Auswahl ändert die Quelle der laufenden Antwort
nicht. **Stopp** und das Schließen der Seitenleiste beenden die laufende Antwort.
Der Chatverlauf ist kein Rückgängig-Journal.

Frage zum Beispiel: „Finde den neuesten Lieferantenvertrag“, „Finde alle PDFs
im Ordner Engineering“ oder „Zeige meine zehn zuletzt geänderten Dokumente“.
Die Suche verwendet zugängliche Dateinamen und Metadaten, auch von freigegebenen
Dateien. „Neueste“ bezieht sich auf die letzte Änderung, nicht auf ein Datum im Text.

Ergebniskarten zeigen Dateiname, übergeordneten Ordner, Änderungsdatum und Quelle.
**In Nextcloud öffnen** öffnet die Datei. **Zum Kontext hinzufügen** hängt zunächst
nur ihre Metadaten an. Inhalte werden bei Bedarf nach dem Senden gelesen. Du
kannst den Anhang entfernen oder ausschließen. Auch eine einzelne Dateiauswahl in
Dateien erscheint als Anhang; mehrere Auswahlen werden nicht automatisch kombiniert.

Frage anschließend nach der Kündigungsfrist oder suche nach einer konkreten Phrase.
PDF-Treffer verwenden tatsächlich extrahierte Seiten, Texttreffer Zeilennummern.
Lass Aussagen mit einem Textausschnitt und einer anklickbaren Quelle belegen.
Bei unvollständiger Extraktion ist die Gesamtzahl der Treffer unbekannt.

Die Suche über Dokumentinhalte benötigt einen unterstützten nativen Nextcloud-
Volltextindex für Dateien. Fehlt er, wird das ausdrücklich erklärt. Es gibt keinen
versteckten Ersatz durch Dateinamen und keinen Download aller Dokumente. TXT,
Markdown, CSV, JSON und Text-PDFs sind für die Suche im einzelnen Dokument geeignet;
Office-Dateien, verschlüsselte PDFs und reine Bildscans werden nicht unterstützt.

Für eine Zusammenfassung als Markdown wähle den Zielordner bewusst aus. Prüfe
Ziel und vollständigen Inhalt in der Freigabekarte, bevor du einmalig erlaubst
oder ablehnst. Vorhandene Dateien werden beim Erstellen nicht überschrieben.
Änderungen setzen das Lesen der aktuellen Revision voraus. Speichere Änderungen
im Editor vor dem Anhängen; der Assistent verwendet die gespeicherte Datei.
