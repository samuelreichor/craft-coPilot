# Release Notes for coPilot

## Unreleased

### Security
- Enforce the changeExecutionMode, changeProvider and changeModel permissions server-side instead of only hiding the UI controls
- Require the editOtherUsersChats permission to append to or compact another user's conversation
- Gate write tools behind an in-code approval flow in supervised mode: the agent pauses, shows the exact changes, and only executes after the user approves
- Validate tool arguments against their JSON schema before execution
- The harness now owns the internal `_siteHandle` argument so the model can no longer redirect tool calls to a different site

### Changed
- Unify the streaming and non-streaming agent loops (empty-stream retries now work on every iteration, stream errors are persisted)
- Add a wall-clock budget (`maxAgentSeconds`) and an optional token budget (`maxTokensPerRequest`) per request
- Truncate oversized tool results before they enter the model context (`maxToolResultTokens`)
- Debug exports now record the provider that was actually used for a turn

## 1.0.5 - 2026-07-13

- Add support for Opus 4.8, Sonnet 5 and Fable 5 to Anthropic provider.
- Add support for GPT 5.6 Sol and GPT 5.5 to OpenAi provider.
- Add support for Gemini 3.5 Flash to Gemini provider.

## 1.0.4 - 2026-05-22

- Prevent errors in entries when no api key is configured

## 1.0.3 - 2026-04-21

- Add support for opus 4.7
- Add support for enabled field in LLMify settings

## 1.0.2 - 2026-04-02

- Only show default provider options that are actually configured
- Harden up provider init event
- Rename plugin name from coPilot to Copilot

## 1.0.1 - 2026-03-30
- Change gitHub url from craft-coPilot to craft-co-pilot

## 1.0.0 - 2026-03-29
- Initial release
