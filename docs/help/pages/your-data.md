# Your data

What the agent sends to your organisation's Fadenstack server, what it keeps, where, and for how long. Your
organisation's privacy policy for Fadenstack applies too; ask your administrator for it.

## What goes to your organisation's server

When you send a message, these go to your organisation's Fadenstack server and on to the model it uses:

- your message;
- the file or folder you included: its name and details, and for a file its text;
- what the agent's tools read while it answers: file names, folders, passages and listings.

The server also sees each tool call **before** it runs, with what the tool is given (for a change, the whole text
to be written), so that your organisation's rules can allow or refuse it.

??? info "More about what the server records"
    - **Each tool call and each approval**: which tool, when, and what was decided. Not the content.
    - **When the agent starts**: the name of your browser's operating system and the app's version, for the
      console's list of your devices.
    - **The AI mark of a file or a copied answer**: a fingerprint of the text (a hash), never the text itself.
    - How long the server keeps these, and who in your organisation can see them, is your organisation's to set:
      see [Your data and privacy](../../use/privacy.md).

## What stays in your browser

- **Your chats and approvals** stay in the page's memory. They are gone when you close or reload the page.
- **The panel's settings** you change are kept in your browser, for your Nextcloud account only.
- **The record of tool calls**: each tool call shows in the chat, with what was decided. The agent's record of them
  stays in the page's memory and is gone with the page. The lasting records are the server's (above) and
  Nextcloud's (below).

## Export a chat

1. Open the settings (the gear in the panel's header).
2. Select **Export chat**.

You get the chat as a Markdown file, marked as made by AI. If your organisation requires that mark and it cannot be
added, the chat is not saved and the panel says so.

## What Nextcloud keeps

For each change you allow, Nextcloud keeps a record of it, so that the change can be undone and checked:

| What | How long |
|---|---|
| The file's text before and after the change, encrypted | Up to 7 days |
| The record of the change, without the text | 30 days more |
| A change whose outcome is unclear | Until it is inspected |

This record is kept whether or not your administrator turned on the agent's history. Nextcloud's own log holds no
content of your files or chats.

## Remove your data

- **Chats**: close or reload the page.
- **Your Nextcloud account deleted**: Nextcloud erases your records of the agent's changes at once.
- **The app disabled or removed** by your administrator: Nextcloud erases all records of the agent's changes. Files
  the agent wrote stay in your Files, with their AI marks.
- **Your Fadenstack account deleted**: the server deletes the agent's records of your tool calls and your devices.

The files the agent wrote are your files: delete them as any other file.
