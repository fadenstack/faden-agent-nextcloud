# Read an approved agent definition

The experimental `nc_agentfile_read` tool reads saved `.agent` files using a
documented **declarative-agent manifest v1.9 subset**. It imports configuration
as data into your existing Fadenstack conversation. It does not activate a new
agent, deploy to Microsoft, import credentials or grant tools or file access.

1. Ask **“Find my approved agent definitions.”**
2. Ask to read one definition, then its declared native knowledge source.
3. Follow its Nextcloud source link to inspect the original. Expand **Imported
   instructions (data only)** when you want to inspect those instructions.

You can also use **Ask Fadenstack** in a saved `.agent` file's Files action
menu. Its selected-file attachment reads the approved definition without
silently loading its knowledge sources.

![Selected agent definition through Files](screenshots/agentfile-selected.png)

![Approved definition and native knowledge in chat](screenshots/agentfile-chat.png)

You must have native access to both the definition and each requested source.
Sharing a definition does not share its knowledge. Unavailable sources show no
native file name or ID. Changes to the definition require a new administrator
binding; changed or revoked sources prevent old content being sent again.
Start a new conversation after reviewing changed sources.

The card inherits Nextcloud/chat typography and uses the Fadenstack icon set.
Source links stay in Nextcloud; imported SharePoint URL references are never
opened or fetched by this reader. The normal working overlay and composer
share **Stop**. A late read result is discarded after Stop. Reads do not create
an edit or an Undo entry.

![Working overlay and Stop](screenshots/agentfile-stop.png)

## Administrator setup

Default off. Keep this provider restricted to an isolated fixture or named
pilot. The tested native version is Nextcloud **35**, with registry API
`declarative-v1.9-files-35-subset-v1` and published SDK **0.3.0-rc.2**.
Enable the existing development/provider-read gates, publish the read tool in
the central agent contract and include it in the native `scope_policy.tools`.
Include each definition and mapped source in the native Files read scope.
Permission to use the Fadenstack agent is independent of native Files access.

Add the provider section to the existing `provider_policy` (preserve its other
sections). Collection IDs are saved native definition file IDs, represented as
strings:

```json
{
  "enabled": true,
  "writesEnabled": false,
  "collections": ["123"],
  "sourceClass": "standard"
}
```

Set the app configuration `agentfile_bindings` separately from the file:

```json
{
  "version": 1,
  "bindings": [{
    "resourceId": "nextcloud:files:123",
    "manifestSha256": "<SHA-256 of the exact saved UTF-8 bytes>",
    "agent": "your-configured-agent-slug",
    "sources": [{
      "reference": "https://example.sharepoint.com/sites/Release/Handbook.md",
      "resourceId": "nextcloud:files:124"
    }]
  }]
}
```

The placeholder digest must be replaced with 64 lowercase hexadecimal
characters. There is no wildcard. Verify the exact native file ID and bytes,
agent slug, classification and intended source mappings before saving the
binding. Every manifest reference must have exactly one external mapping;
the mapping never overrides native user permissions or Files scope. Renaming
the same saved `.agent` file preserves its ID; a copy has a new ID and requires
approval. Disabling the provider stops new reads. It does not erase content
already delivered to a conversation.

## Supported bounds

| Input | Supported subset |
|---|---|
| Manifest | UTF-8 JSON object, at most 64 KiB, depth 16; duplicate and unknown properties refused |
| Identity/text | Exact v1.9 schema URL/version; name, description, instructions; optional id and up to 12 conversation starters |
| Knowledge | One `OneDriveAndSharePoint` capability with 1–5 explicit `items_by_url` references |
| Native source | Exact administrator-mapped `.md` or `.txt` file, at most 16 KiB per read |
| Deployment/actions | Unsupported; no actions, skills, credentials, arbitrary selectors or manifest edits |

Definition/source metadata and content revisions are checked before model
requests, including replay. Approval binds exact definition bytes, not just a
native ETag. Current native authorization, configuration and classification
still apply. These are read guards, not a lock against subsequent native edits.

The parser is independently written; no Microsoft schema is bundled. This is
optional compatibility with the documented subset, not universal SharePoint
`.agent` compatibility or Microsoft certification. Exact schema redistribution,
authentic legacy-file compatibility and commercial IP clearance remain separate
gates; no patent-free claim is made. See the
[Microsoft v1.9 specification](https://learn.microsoft.com/en-us/microsoft-365/copilot/extensibility/declarative-agent-manifest-1.9).

Local HTTP and composed-SDK tests do not certify real cloud streaming, a
supported package upgrade/rollback or pilot rollout. Keep those release gates
open until their evidence is complete.
