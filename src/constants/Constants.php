<?php

namespace samuelreichor\coPilot\constants;

final class Constants
{
    // Table Names
    public const TABLE_CONVERSATIONS = '{{%copilot_conversations}}';
    public const TABLE_AUDIT_LOG = '{{%copilot_audit_log}}';
    public const TABLE_BRAND_VOICE = '{{%copilot_brand_voice}}';

    // Cache Keys
    public const CACHE_SCHEMA_PREFIX = 'copilot.schema.';

    // Permission Keys — Chat
    public const PERMISSION_VIEW_CHAT = 'copilot-viewChat';
    public const PERMISSION_DELETE_CHAT = 'copilot-deleteChat';
    public const PERMISSION_CREATE_CHAT = 'copilot-createChat';
    public const PERMISSION_VIEW_OTHER_USERS_CHATS = 'copilot-viewOtherUsersChats';
    public const PERMISSION_EDIT_OTHER_USERS_CHATS = 'copilot-editOtherUsersChats';
    public const PERMISSION_DELETE_OTHER_USERS_CHATS = 'copilot-deleteOtherUsersChats';

    // Permission Keys — Brand Voice
    public const PERMISSION_VIEW_BRAND_VOICE = 'copilot-viewBrandVoice';
    public const PERMISSION_EDIT_BRAND_VOICE = 'copilot-editBrandVoice';

    // Permission Keys — Audit Log
    public const PERMISSION_VIEW_AUDIT_LOG = 'copilot-viewAuditLog';

    // Cookie Names
    public const COOKIE_PROVIDER = 'co_pilot_provider';
    public const COOKIE_MODEL = 'co_pilot_model';

    // Schema — Field classes excluded from schema output
    /** @var array<int, string> */
    public const EXCLUDED_FIELD_CLASSES = [
        'nystudio107\seomatic\fields\SeoSettings',
    ];
}
