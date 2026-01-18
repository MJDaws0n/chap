# ChapScribe (Template Script) Syntax

ChapScribe is Chap's tiny, JSON-based template scripting language.

It is intentionally limited for security:
- No code execution (`eval`), no filesystem access, no loops.
- Strict schema validation.
- Deterministic pause/resume when prompting the user.

## Where scripts live

Each template may optionally include a script file. In the template `config.json`, add:

```json
{
  "chap_script": "chap-script.json"
}
```

The value is a path relative to `config.json` (the template root). The file must be JSON.

## When scripts run

Chap runs the template script as a *pre-deploy* check whenever a template-based application is deployed/redeployed.

If the script needs user input, the deployment is paused and the API returns `409 action_required` with a prompt payload.

## Script format

```json
{
  "chap_script_version": 1,
  "name": "Optional human name",
  "steps": [
    { "type": "..." }
  ]
}
```

## Values

Many fields can use either a literal or a reference:

- Literal: `"hello"`, `123`, `true`
- Env reference: `{ "env": "EULA" }`
- Variable reference: `{ "var": "accept_eula" }`

## Step types

### `set_env`
Sets an application environment variable.

```json
{ "type": "set_env", "key": "EULA", "value": "TRUE" }
```

### `set_var`
Sets an internal variable.

```json
{ "type": "set_var", "var": "mode", "value": "paper" }
```

### `if`
Conditional branching.

```json
{
  "type": "if",
  "condition": { "op": "not_truthy", "value": { "env": "EULA" } },
  "then": [ { "type": "..." } ],
  "else": [ { "type": "..." } ]
}
```

Supported condition operators:
- `eq` / `neq` (case-insensitive for strings)
- `is_truthy` / `not_truthy`

#### `eq` / `neq` examples

Compare an env var to a string:

```json
{
  "type": "if",
  "condition": { "op": "eq", "left": { "env": "MODE" }, "right": "paper" },
  "then": [ { "type": "set_env", "key": "SERVER_JAR", "value": "paper.jar" } ],
  "else": [ { "type": "set_env", "key": "SERVER_JAR", "value": "vanilla.jar" } ]
}
```

Compare a prompted variable:

```json
{
  "type": "if",
  "condition": { "op": "neq", "left": { "var": "difficulty" }, "right": "hard" },
  "then": [ { "type": "set_env", "key": "ALLOW_CHEATS", "value": "TRUE" } ],
  "else": [ { "type": "set_env", "key": "ALLOW_CHEATS", "value": "FALSE" } ]
}
```

Notes:
- String comparisons are case-insensitive (`"TRUE"` equals `"true"`).
- If both sides are numeric, comparison is numeric (`"2"` equals `2`).
- If either side is boolean, values are compared using ChapScribe truthiness rules.

Truthiness accepts common values like: `true`, `1`, `yes`, `on` (case-insensitive).

### `prompt_confirm`
Asks for a confirmation.

```json
{
  "type": "prompt_confirm",
  "var": "accept_eula",
  "title": "Accept EULA",
  "description": "You must accept the EULA to proceed.",
  "links": [
    { "label": "View EULA", "url": "https://example.com/eula" }
  ],
  "confirm": { "text": "Accept", "variant": "danger" },
  "cancel": { "text": "Cancel" }
}
```

`variant` can be `neutral`, `success`, or `danger`.

### `prompt_value`
Asks the user for a value (string/number/select).

String:
```json
{
  "type": "prompt_value",
  "var": "server_name",
  "input_type": "string",
  "title": "Server name",
  "description": "Choose a display name",
  "links": [
    { "label": "Naming rules", "url": "https://example.com/rules" }
  ],
  "placeholder": "My Server"
}
```

Links are strictly limited to safe external `http(s)` URLs (no HTML, no `javascript:`).

Number:
```json
{
  "type": "prompt_value",
  "var": "max_players",
  "input_type": "number",
  "title": "Max players"
}
```

Select:
```json
{
  "type": "prompt_value",
  "var": "difficulty",
  "input_type": "select",
  "title": "Difficulty",
  "options": [
    { "label": "Easy", "value": "easy" },
    { "label": "Normal", "value": "normal" },
    { "label": "Hard", "value": "hard" }
  ]
}
```

### `stop`
Stops deployment with a message.

```json
{ "type": "stop", "message": "Cancelled" }
```

## Example: Minecraft EULA gate

See `templates/minecraft-vanilla/chap-script.json`.
