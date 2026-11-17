# KI-Kennzeichnung {#ai-marks}

Was der Agent schreibt, ist als KI-generiert gekennzeichnet, damit Sie und andere es erkennen können. Eine Datei,
die er speichert, trägt in Nextcloud einen signierten Nachweis, eine Markdown-Datei zeigt die Kennzeichnung
zusätzlich an ihrem Anfang, und formatierter Text, den Sie aus dem Chat kopieren, trägt eine verborgene Kennzeichnung.
Behalten Sie diese Kennzeichnungen bei, wenn Sie den Text bearbeiten oder weitergeben.

<!-- picture: marks/front-matter.png — a Markdown file the agent wrote, open in Nextcloud Text, with the front matter box holding ai-generated: true and the ai-record-id -->

## Im Chat {#in-the-chat}

Unter dem Nachrichtenfeld steht **Antworten werden von einer KI erzeugt und können falsch sein. Prüfen Sie
Wichtiges.** Ein Screenreader liest diese Zeile vor, wenn Sie in das Nachrichtenfeld wechseln.

## In den Dateien, die der Agent speichert {#in-the-files-the-agent-saves}

Jede Datei, die der Agent erstellt oder ersetzt, erhält einen Nachweis, den der Fadenstack-Server Ihrer
Organisation signiert, außer die Datei braucht keine Kennzeichnung: eine Übersetzung, eine Korrektur oder eine kurze
Bezeichnung. Nextcloud bewahrt den Nachweis in den Metadaten der Datei auf. Eine Markdown-Datei
(`.md`) beginnt außerdem mit einem kurzen Kopf, ihrem Front Matter, der `ai-generated: true` sagt und den Nachweis
nennt (`ai-record-id`). Nextcloud Text zeigt ihn als Kasten am Anfang der Datei.

Das Front Matter sehen Sie auf der Freigabekarte als Teil des Textes, bevor Sie die Änderung erlauben. Nach dem
Speichern sagt eine Zeile darunter, wie die Datei gekennzeichnet ist:

| Die Zeile sagt | Das bedeutet |
|---|---|
| **Als KI-generiert gekennzeichnet, in den Metadaten der Datei und in ihrem Front Matter.** | Eine Markdown-Datei, auf beide Arten gekennzeichnet |
| **Als KI-generiert gekennzeichnet, in den Metadaten der Datei.** | Eine Textdatei, in ihren Metadaten gekennzeichnet |
| **Nur im Front Matter als KI-generiert gekennzeichnet: Nextcloud hat die Metadaten der Datei nicht behalten.** | Der Kopf ist da; sagen Sie Ihrem Administrator, dass der Nachweis fehlt |
| **Die Kennzeichnungsrichtlinie des Agenten erforderte keine neue KI-Kennzeichnung.** | Die Regeln Ihrer Organisation verlangten für diese Änderung keine neue Kennzeichnung |
| **Ohne KI-Kennzeichnung gespeichert:** und ein Grund | Die Datei wurde gespeichert, aber nicht gekennzeichnet. Der Grund sagt, warum |

Die Zeile **Die Kennzeichnungsrichtlinie des Agenten erforderte keine neue KI-Kennzeichnung.** erscheint nur, wenn
**Hinweis, wenn eine Antwort kein KI-Kennzeichen trägt** in den Einstellungen eingeschaltet ist (das Zahnrad in der
Kopfzeile des Panels).

## Was die Kennzeichnung erhält {#what-keeps-the-mark}

| Sie | Der Nachweis der Datei | Der Markdown-Kopf |
|---|---|---|
| Die Datei umbenennen, verschieben oder teilen | Bleibt | Bleibt |
| Eine neue Version speichern oder sie in Nextcloud Text bearbeiten | Bleibt | Bleibt, außer Sie löschen ihn |
| Sie löschen und aus dem Papierkorb wiederherstellen | Bleibt | Bleibt |
| Sie in Nextcloud kopieren | Bleibt: Die Kopie erhält ihn auch | Bleibt |
| Sie herunterladen | Nicht in der heruntergeladenen Datei | Bleibt in der Datei |

??? info "Mehr zum Nachweis"
    - Der Nachweis sagt, dass der Text beim Speichern von einer KI erstellt wurde. Eine spätere Bearbeitung durch
      eine Person ändert den Nachweis nicht; dass er da ist, beweist also nicht, dass der heutige Text noch der ist,
      den er beschreibt.
    - Niemand kann den Nachweis über WebDAV ändern, und Nextcloud Text fügt beim ersten Speichern eine leere Zeile
      nach dem Kopf ein; die Kennzeichnung bleibt.

## In Antworten, die Sie kopieren {#in-answers-you-copy}

Kopieren Sie eine Antwort mit ihrer Kopieren-Schaltfläche, trägt der kopierte formatierte Text eine verborgene
KI-Kennzeichnung mit der Nummer des Nachweises. Reiner Text wird unverändert kopiert, und Code wird nicht
gekennzeichnet. Von den getesteten Editoren behalten TinyMCE und CKEditor 5 die Kennzeichnung beim Einfügen;
Outlook im Web, Lexical, ProseMirror, Quill, Slate und Tiptap verwerfen sie; Word und die Outlook-App sind nicht
getestet. Verlassen Sie sich nach dem Einfügen nicht auf die Kennzeichnung.

Verlangt Ihre Organisation die Kennzeichnung und lässt sie sich nicht anbringen, wird nichts kopiert, und das Panel
sagt **Nicht kopiert: Diese Antwort konnte nicht als KI-generiert gekennzeichnet werden, und Ihre Organisation
verlangt das.**

## Einen Text oder eine Datei prüfen {#check-a-text-or-a-file}

Um herauszufinden, ob ein Text oder eine Datei eine Fadenstack-Kennzeichnung trägt, nutzen Sie **Inhalte prüfen** auf
dem Fadenstack-Server Ihrer Organisation: siehe [Inhalte prüfen](../../use/checking-content.md).
