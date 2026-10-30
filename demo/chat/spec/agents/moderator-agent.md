# Framework Moderation Used By Chat

Chat has no project-owned `ModeratorAgent`. `ChatAgent` opens shared moderation
checks and applies their verdicts; the framework `ModerationAgent` reads the
common check collection and runs the selected framework profile.

For the supported input shapes, upload metadata, image copies, fixed deadlines,
and refusal handling, see the canonical
[moderation guide](../../../../docs/agents/architecture/moderation.md). The
chat-specific message and attachment path is described in
[`file-moderation-flow.md`](../data-flow/file-moderation-flow.md).
