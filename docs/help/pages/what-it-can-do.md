# What it can do

The agent works in your Nextcloud Files. It finds and reads files, answers questions about them, and creates or
updates text files after you allow each change. This page lists its tools and its limits.

## Its tools

| The agent can | Asks you first |
|---|---|
| Find files and folders by name, type, folder or date | No |
| Show the details of a file it found, or of the one you included | No |
| List what is in the folder you included | No |
| Read the file you included, or a file it found | No |
| Find passages in one document, with the page or line | No |
| Search the contents of your files, where Nextcloud has a full-text index | No |
| Create a `.md` or `.txt` file | **Yes, every time** |
| Replace the text of a `.md` or `.txt` file | **Yes, every time** |

Tools that only read run without a card. Each change shows its own card, with the whole text: see
[Ask and approve](ask-and-approve.md).

Your Nextcloud administrator can also turn on, with Nextcloud's `occ` command:

- History (`history_enabled`): undo and redo the agent's updates of a text file, each with its own approval (see
  [Undo a change](ask-and-approve.md#undo)).
- Folder overview and shares (`files_reads_enabled`): list a folder's tree, and with whom a file is shared.

## What it works on

- Your own Files: what you can open in Nextcloud, including files others shared with you. Your Nextcloud
  permissions apply to every step; the agent cannot open what you cannot.
- In the standard setup it writes only into your own files, and shares nothing with anyone.
- It reads text, Markdown, CSV and JSON files and PDFs with text in them.

## Limits

- **One file or folder per message.** Several files are not combined.
- **Formats it cannot read:** Word, Excel and PowerPoint files, scanned pages without text, and PDFs with a
  password. Some PDFs are read only in part; the answer then says so.
- **Sizes:** text files up to 2 MiB and PDFs up to 10 MiB; it reads up to 50,000 characters and 50 pages of one file.
  What it writes is up to 256 KiB.
- **Search in file contents** needs Nextcloud's full-text search. Without it, the agent says so; it does not search
  file names instead without telling you.
- **"Latest"** means last changed, not a date written in the document.
- **Per message:** at most 30 tool calls and 10 changes. A file you included stays valid for one hour, and a card
  waits up to ten minutes.
- **Answers can be wrong.** The agent can misread a document, and a file from someone else can contain text written to
  mislead it. Check the passages for what matters, and read each change before you allow it.

??? info "More about the experimental setting"
    Your administrator can switch on **Experimental: development mode** for testing. With further settings it lets the
    agent use other Nextcloud apps (such as Calendar, Contacts, Deck, Tables, Mail and Talk), create folders, copy,
    move and share files, and call outside workflow services. These tools are untested, may change, and some send
    content to other people. It can also switch off the AI marks on files. It is not meant for everyday work. The
    [Product information](product-information.md) page describes it.
