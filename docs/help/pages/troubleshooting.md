# When it does not work

The messages you may see in the agent's panel, what they mean, and what to do. When a change was refused or stopped,
nothing was saved unless the chat says **Saved:**.

## Signing in and starting

| You see | What to do |
|---|---|
| **Ask your administrator to configure and enable Fadenstack Agent in Additional settings.** | The app is not connected to a Fadenstack server yet. Your Nextcloud administrator completes [the setup](get-started.md#install) |
| **Connection unavailable** in **Assistant details** | The Fadenstack server address in Nextcloud's settings is not valid. Ask your Nextcloud administrator |
| The panel cannot reach your organisation's Fadenstack server | Try again later. If it stays, tell your administrator |
| **Your sign-in expired. Sign in again.** | Sign in again: see [Sign in](sign-in.md) |
| The code expired before you approved it | Select **Cancel**, then **Sign in with a code** for a new one |
| **Your organisation turned this agent off.** | Your organisation switched the agent off. Your typed text stays. Select **Check again** later, or ask your administrator |

## Files and context

| You see | What to do |
|---|---|
| **The selected file changed. Select and include it again.** | The file or folder changed after you included it, for example because someone saved it. Select it again in Files, then send |
| **This file context expired. Select and include the file again.** | A file you include stays valid for one hour. Select it again, then send |
| **Only the saved document is available. Save your edits before sending a message.** | The agent reads the saved file. Save your edits, then send |
| **The active document changed. Reopen the assistant to attach it.** | You switched documents in the full-screen editor. Close the panel and open it again from the editor |
| **Indexed document search is unavailable on this server. Search filenames or an attached document instead.** | Your Nextcloud has no full-text search for Files. Search by name, or include one document and ask about it |
| The agent says it cannot read a file | It reads text, Markdown, CSV, JSON and PDFs with text in them, up to the sizes in [Limits](what-it-can-do.md#limits). Save a copy in one of those formats |

## Changes

| You see | What to do |
|---|---|
| The card is gone, or says it expired | A card waits up to ten minutes. Ask again for a new card |
| **The native view did not finish loading. Nothing was changed. Try again.** | Nextcloud's page was not ready. Nothing was saved. Ask again |
| **Saved in Nextcloud. Refresh the native view after saving or cancelling your edits.** | The file was saved while you had it open with unsaved edits. Save or cancel your edits, then reload the view |
| **Saved in Nextcloud. Open the changed resource to see it.** | The file was saved. Open it to see the change |
| **Nextcloud confirmed the file save. Start a new conversation; …** | The file was saved, but the chat cannot go on safely. Select **New chat** |
| **Nextcloud confirmed the Files action. Start a new conversation; …** | The same, after an action on files. Select **New chat** |
| **Saved without an AI mark:** and a reason | The file was saved, but not marked: see [AI marks](ai-marks.md) |
| **Undo file change** is not offered | Undo needs the agent's history (your administrator turns it on), works up to 7 days after the change, and only while nobody has edited the file since. Use the file's **Versions** in Nextcloud instead |

## Answers

| You see | What to do |
|---|---|
| The answer takes long | Wait, or select **Stop** (or press Escape) and ask a narrower question |
| **Not copied: this answer could not be marked as AI-generated, and your organisation requires it.** | Try again later. If it stays, tell your administrator |
| **This answer carries no machine-readable AI mark. Your organisation allowed this model to answer without one.** | Your organisation's choice for this model. Treat the answer as made by AI all the same |
| An error from your organisation's server instead of an answer | Try again. If it stays, tell your administrator, with the time it happened |

Still stuck? See [More help](more-help.md).
