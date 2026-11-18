# Product information

What the Nextcloud agent is for and what not, its limits and known risks, what is logged, the settings your Nextcloud
administrator can fix, how long it gets security updates, and where to report a security problem. How to use it is on
the other pages of this Help.

## What it is for

The Nextcloud agent is an assistant in Nextcloud Files. It finds and reads your own files, answers questions about them
and, with your approval of each change, creates or replaces text files. It is for adults, for office work, used by one
person at a time. This version is a preview. It works with your organisation's Fadenstack server, which your Nextcloud
administrator connects; it has no "own AI" mode, and Fadenstack runs no server for you. The app is free software under
the AGPL.

**You stay in charge.** The agent works on your instruction and asks before it changes things. AI makes mistakes: it can
misread a document, follow misleading content or write something wrong. Check what it writes and does before you rely on
it, above all before anything is sent, published, deleted or paid. Do not let it make important legal, medical,
financial or safety decisions on its own, and do not use its output as the only basis for a decision about a person.

**Not for high-risk uses.** The agent is not made for the uses that the EU AI Act lists as high-risk (Annex III), and it
must not be changed into, or used as, a high-risk AI system. Do not use it:

- to identify or categorise people by biometric data, or to recognise emotions;
- as a safety component of critical infrastructure, such as traffic, water, gas, heating or electricity;
- in education, to decide on admission, grade learning, place people in a level of education, or watch for cheating
  in tests;
- at work, to select or rank job applicants, decide on promotion, dismissal or other terms of work, assign tasks by
  people's behaviour or traits, or monitor how people work or behave;
- to decide on public benefits or services, assess creditworthiness, price life or health insurance, or sort emergency
  calls;
- for law enforcement, migration, asylum or border control;
- to help courts or authorities find or apply the law, or to influence elections or how people vote.

This is information about the intended purpose, not a licence term. Whoever uses the agent for such a purpose uses it
outside its intended purpose and may become the provider of a high-risk AI system, with the duties that come with it.
The full text is in [Privacy policy and terms](https://fadenstack.com/legal/nextcloud/).

## Limits

- It works on your own Files, with your Nextcloud permissions: it cannot open what you cannot. In the standard setup it
  writes only into your own files and shares nothing with anyone. What it can read and how large: see
  [What it can do](what-it-can-do.md).
- It writes only text files (`.txt` and `.md`). Every change asks first, with the whole text on the card, whatever the
  tool mode (**Tool mode**) says: see [Ask and approve](ask-and-approve.md).
- A file you select is sent only when you include it and send your message; the chip shows **Next message**, or
  **Not included** when you took the file out.
- Your browser can reach only your organisation's Fadenstack server.
- It stops when you select **Stop**. Your chats and approvals stay in the page's memory and are gone when you close or
  reload it.
- What it writes carries an AI mark: a signed record in Nextcloud and, in a Markdown file, two lines at the top. A file
  that needs no mark (a translation, a correction, a short label) is saved without one; the chat says so only if you
  switched on **Tell me when an answer carries no AI mark**, with the line **No new AI mark was required by the agent's
  marking policy.** Whether a mark survives a paste depends on the program: see [AI marks](ai-marks.md).

## Known risks

- A file from someone else can hide instructions in its text. The agent treats file text as content, but it can still be
  misled. Read each approval card before you allow it.
- What the agent reads goes to your organisation's server and on to the model. See [Your data](your-data.md).
- For undo and as evidence, Nextcloud keeps the text of each file before and after every change you approve, encrypted,
  for up to 7 days.
- The experimental setting (**Experimental: development mode**) is for testing only, never for production. It unlocks
  tools for other Nextcloud apps (Mail, Talk, Calendar, Contacts, Deck, Tables and others) and for outside workflow
  services (n8n, Windmill). Some of them send mail and messages to other people or call outside systems, and they are
  untested. Your own cookie is passed on to Nextcloud's own address, and your key for an outside service goes to that
  service. An administrator can also switch the AI marks on files off then: the agent writes files without a mark, and
  the approval card does not show whether marking is on. Do not publish such a file as if it carried one. If you publish
  such a text to inform the public on matters of public interest, you must label it as made with AI yourself.
- The agent's own record of tool calls is kept only in the page's memory and cannot be exported; the lasting records are
  your organisation's server and Nextcloud (below).

## What is logged

- **On your organisation's server,** the app reports after tool runs and approvals: which tool ran, how long it took and
  how it ended, your approval decisions, and statistics for each chat. Never what was read or written. Neither you nor
  your administrator can switch these reports off. At start it also reports a short description of your operating
  system and the app's version; the server keeps the latest report for each device and removes a device it has not heard
  from for 90 days.
- The server also receives what goes to the model (your messages, the included file's details and text, and what the
  tools read) and, before every tool runs, that tool's whole input: the text to write, the search words. This check
  cannot be switched off either.
- A hash of each file the agent writes, and of each answer you copy, goes to the server so that it can sign the AI mark.
  Never the text.
- **In Nextcloud,** for each change you approve, a journal keeps the file's text before and after, encrypted, for
  7 days, and the record of the change without the text for 30 more days. A change whose result is unclear is kept until
  it is inspected. This happens whether or not History is on. Nextcloud's own log holds no content.
- **In this browser,** only your language and a display setting are kept.
- **What erases the journal:** deleting your Nextcloud account erases your rows at once (if that fails, they expire as
  above); disabling or removing the app erases the whole journal, all open approvals and the keys people stored for
  outside workflow services, unless the administrator removes the app with `occ app:remove --keep-data`. Files the agent
  wrote stay in your Files, with their marks.
- Content is not written to any log, and there is no switch for it. See [Your data](your-data.md) for the rest, and for
  how to export a chat.

## Settings your administrator can fix

Your Nextcloud administrator sets these in the Nextcloud administration, under "Additional settings", in the form
**Fadenstack Agent**, and with Nextcloud's `occ` command (app values). A change takes effect when people reload Files.

| Setting or app value | What it does | If not set |
|---|---|---|
| **Enable integration** | Switches the agent on for everyone | off |
| **Fadenstack server origin** | Your organisation's Fadenstack server, with `https://`; the only address the app talks to | none |
| **Published agent identifier** | The agent the Fadenstack administrator published for Nextcloud | none |
| `history_enabled` | History: tools to undo, redo or inspect an earlier update of a text file, each with its own approval | off |
| `files_reads_enabled` | Folder overview and shares: lists a folder's tree and with whom a file is shared | off |
| `scope_policy` | What the agent may reach and how much it may do in one turn | only your own Files, private outputs, 30 calls, 10 changes and 256 KiB per turn |
| **Experimental: development mode** | Testing only. Unlocks tools for other apps and outside workflow services, each with its own app value, and lets an administrator switch the AI marks off (`file_provenance_enabled` set to `0`); also allows HTTP on localhost | off |

The administrator cannot switch off the reports to the server, the server's check before each tool runs, or, in
production, the AI marks. How long the journal is kept is fixed. There is no policy file as in the other agents.

## Security updates and support

The Nextcloud agent gets security updates until at least **8 December 2031**. We look into every security flaw reported
to us, fix it without undue delay, and tell you when a flaw is actively exploited. We fix the newest version only; your
Nextcloud administrator updates the app. Keep Nextcloud up to date too.

- **A security problem:** report it privately, as [Support and contact](https://fadenstack.com/support/#security) and
  our [security.txt](https://fadenstack.com/.well-known/security.txt) say. Never in a public issue.
- **Help and other questions:** [Support and contact](https://fadenstack.com/support/). The source code and the issue
  tracker are at [faden-agent-nextcloud](https://github.com/fadenstack/faden-agent-nextcloud).
- **Privacy policy and terms:** [fadenstack.com/legal/nextcloud](https://fadenstack.com/legal/nextcloud/).
