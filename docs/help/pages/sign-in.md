# Sign in

The panel signs in to your Fadenstack account on your organisation's server. Sign in with a code: you approve it on
the Fadenstack server, so your Fadenstack password is never typed into Nextcloud.

<!-- picture: sign-in/panel.png — the panel's Sign in to Fadenstack screen with Sign in with a code, and Email and Password below -->

## Before you start

- You are signed in to Nextcloud and have opened the agent (see [Get started](get-started.md)).
- You have a Fadenstack account with access to the agent. If you do not, ask your administrator.

## Sign in with a code

1. In the panel, select **Sign in with a code**.
2. The panel shows a code. Select **Open sign-in page**. Fadenstack's **Sign in an app** page opens in a new tab with
   the code filled in.
3. If that tab is not signed in to Fadenstack yet, sign in as usual: with your password, or with your organisation's
   sign-in.
4. Check that the **Code** is the one the panel shows, then select **Continue**.
5. Check that the app asking is **Nextcloud Files**, then select **Approve**.

<!-- picture: sign-in/code.png — the panel showing the code, Open sign-in page, Waiting for approval… and The code expires at … -->

Go back to Nextcloud. The panel finishes signing in by itself and shows the chat.

??? info "More about signing in with a code"
    - The code works once and expires at the time the panel shows (**The code expires at …**). To start again, select
      **Cancel**, then **Sign in with a code**.
    - Opening the sign-in page approves nothing; only **Approve** does. If you did not start the sign-in, select
      **Deny**.
    - What the approval page shows, and how to see and end your sign-ins later: [Sign in a desktop app](../../use/sign-in-an-app.md).

## Sign in with your email and password

You can also type your Fadenstack email and password into **Email** and **Password**, then select **Sign in**. They
go to your Fadenstack server only; Nextcloud does not keep them.

## How long you stay signed in

The sign-in lasts while the Nextcloud page is open, and renews itself while you work. When you reload the page or
open Nextcloud in another tab, you sign in again.

To sign out, open the settings (the gear in the panel's header) and select **Sign out**.

## When it does not work

| You see | What to do |
|---|---|
| **Your sign-in expired. Sign in again.** | Sign in again |
| The code expired before you approved it | Select **Cancel**, then **Sign in with a code** for a new code |
| **Your organisation turned this agent off.** | Your organisation switched the agent off on the Fadenstack server. You can keep your typed text; select **Check again** later, or ask your administrator |
| The sign-in page marks the agent as not available to you, or does not let you approve | You have no access to the agent yet. Ask your administrator |
| **Connection unavailable** in **Assistant details** | The Fadenstack server address in Nextcloud's settings is not valid. Ask your Nextcloud administrator |
