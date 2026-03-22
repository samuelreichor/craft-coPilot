<div align="center">
	<a href="https://packagist.org/packages/samuelreichor/craft-co-pilot" align="center">
      <img src="https://online-images-sr.netlify.app/assets/craft-co-pilot.png" width="100" alt="Craft CoPilot">
	</a>
  <br>
	<h1 align="center">AI Agent for Craft CMS</h1>
  <p align="center">
    An AI Agent for Craft CMS in your control panel, it understands, creates, edits, translates, and publishes content across all your sites and languages using natural conversation.
  <br/>
</div>

<p align="center">
  <a href="https://packagist.org/packages/samuelreichor/craft-co-pilot">
    <img src="https://img.shields.io/packagist/v/samuelreichor/craft-co-pilot?label=version&color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-co-pilot">
    <img src="https://img.shields.io/packagist/dt/samuelreichor/craft-co-pilot?color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-co-pilot">
    <img src="https://img.shields.io/packagist/php-v/samuelreichor/craft-co-pilot?color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-co-pilot">
    <img src="https://img.shields.io/packagist/l/samuelreichor/craft-co-pilot?color=blue">
  </a>
</p>

## Features

- **AI Chat in the Control Panel**: A full chat interface and an entry slideout — talk to your content without leaving the page you're editing
- **Read, Write & Publish**: Create entries, fill fields, update content, and publish — all through natural language
- **Multi-Site Translation**: Translate entries across sites and languages with automatic propagation handling
- **Multi-Provider Support**: Use Anthropic, OpenAI, or Google Gemini — switch models per conversation
- **Granular Permissions**: Control read, write, or block access per section, volume, and category group
- **Brand Voice & Glossary**: Define tone, terminology, and forbidden words to keep content on brand
- **Custom Commands & Tools**: Register your own slash commands and tools via events to extend the agent with project-specific capabilities
- **Audit Log**: Full traceability of every read, create, and update the agent performs — with field-level diffs
- **Web Search**: Let the agent browse the web to research and enrich your content (Anthropic only)

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- API key for at least one provider: OpenAI, Anthropic, or Google Gemini

## Which Provider Should You Choose?

All three providers work well with CoPilot, but they differ in cost, speed, and how reliably they handle multi-step tool workflows.

| Provider                                           | Strengths | Tradeoffs |
|----------------------------------------------------|-----------|-----------|
| **[OpenAI](https://platform.openai.com/login)**    | Best balance of cost and capability. Reliable tool calling, fast responses. | Slightly less nuanced writing than Anthropic. |
| **[Anthropic](https://platform.claude.com/login)** | Strongest writing quality and reasoning. Handles complex nested content well. | Higher token cost. Slower due to extended thinking. |
| **[Google Gemini](https://aistudio.google.com/welcome)**                              | Most affordable option. Large context window. | Less consistent with multi-step tool chains. May need retries on complex tasks. |

**Our recommendation:** Start with **OpenAI** for the best all-around experience. Switch to **Anthropic** when writing quality matters most, or use **Gemini** for high-volume, cost-sensitive workflows.

## Installation

> [!NOTE]  
> Docs are coming soon. This plugin is still under active development and testing.

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
    'defaultProvider' => 'openai',

    // API key env var names (must be set in .env)
    'openaiApiKeyEnvVar' => '$OPENAI_API_KEY',
    'anthropicApiKeyEnvVar' => '$ANTHROPIC_API_KEY',
    'geminiApiKeyEnvVar' => '$GEMINI_API_KEY',
];
```

All settings are optional, defaults work out of the box with just an API key.

## Support

If you encounter bugs or have feature requests, [please submit an issue](/../../issues/new). Your feedback helps improve the plugin!
