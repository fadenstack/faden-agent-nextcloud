# Your data

What the agent sends to your organisation's Fadenstack server, what it keeps, where, and for how long. Your
organisation's privacy policy for Fadenstack applies too; ask your administrator for it.

## What goes to your organisation's server

When you send a message, these go to your organisation's Fadenstack server and on to the model it uses:

- your message and the chat so far;
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
  stays in the page's memory and is gone with the page; while the page is open, you can save it as a web page
  ([below](#your-data-in-this-browser)). The lasting records are the server's (above) and Nextcloud's (below).
- **A log of the agent's own work**: which requests it made to the server and when, which tools ran, and errors,
  never your messages, the answers, file contents or what a tool was given. It is kept in your browser for your
  account, at most 500 entries; entries older than 7 days are deleted the next time the agent writes to the log.
  The first entries after the page opens come before the agent knows who is signed in; they go to a log of this
  browser without a person. **Report a problem** copies the log's last entries for your IT.

## Your data in this browser {#your-data-in-this-browser}

Open the settings (the gear in the panel's header) and select **Your data in this browser**:

- **Export my data** saves what the agent keeps for you in this browser as a file.
- **Remove my data…** deletes it, after asking: your log and the panel's settings.
- **Remove all my Fadenstack data in this browser…** deletes, after asking, all the Fadenstack data this browser
  keeps for you on this Nextcloud, including the log kept before the agent knew who was signed in.
  **Export all my Fadenstack data** saves all of it as a file.
- **Export the record of tool calls…** saves the agent's record of tool calls as a web page, while the page is
  open. A line above it says how many entries the record holds and whether it is intact.

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
- **The log and the panel's settings**: **Remove my data…** in **Your data in this browser** (above). Entries older
  than 7 days are deleted the next time the agent writes to the log. The log kept before the agent knew who was
  signed in goes only with **Remove all my Fadenstack data in this browser…**.
- **Your Nextcloud account deleted**: Nextcloud erases your records of the agent's changes at once. If that fails,
  they expire as the table above says.
- **The app disabled or removed** by your administrator: Nextcloud erases all records of the agent's changes and the
  keys people stored for outside workflow services, unless the administrator removes the app and keeps its data on
  purpose. Files the agent wrote stay in your Files, with their AI marks.
- **Your Fadenstack account deleted**: on a Fadenstack server 0.7.1 or newer, the server deletes the agent's records of
  your tool calls and your devices.

The files the agent wrote are your files: delete them as any other file.
