# CoPilot for Craft CMS

AI content assistant plugin for Craft CMS 5.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- API key for at least one provider: OpenAI, Anthropic, or Google Gemini

## Installation

```bash
composer require samuelreichor/craft-co-pilot:^0.0.0@RC
php craft plugin/install co-pilot
```

## Configuration

Add your API key to `.env`:

```
OPENAI_API_KEY=sk-...
```

Create `config/co-pilot.php`:
> This file will overwrite the settings in the control panel.
```php
<?php

return [
    // Provider: 'openai', 'anthropic', or 'gemini'
    'activeProvider' => 'openai',

    // API key env var names (must be set in .env)
    'openaiApiKeyEnvVar' => '$OPENAI_API_KEY',
    'anthropicApiKeyEnvVar' => '$ANTHROPIC_API_KEY',
    'geminiApiKeyEnvVar' => '$GEMINI_API_KEY',
];
```

All settings are optional — defaults work out of the box with just an API key.
