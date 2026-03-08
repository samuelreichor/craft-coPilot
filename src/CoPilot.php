<?php

namespace samuelreichor\coPilot;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use samuelreichor\coPilot\constants\Constants;
use samuelreichor\coPilot\models\Settings;
use samuelreichor\coPilot\services\AgentService;
use samuelreichor\coPilot\services\AuditService;
use samuelreichor\coPilot\services\ContextService;
use samuelreichor\coPilot\services\ConversationService;
use samuelreichor\coPilot\services\FieldNormalizer;
use samuelreichor\coPilot\services\PermissionGuard;
use samuelreichor\coPilot\services\ProviderService;
use samuelreichor\coPilot\services\SchemaService;
use samuelreichor\coPilot\services\SystemPromptBuilder;
use samuelreichor\coPilot\services\TransformerRegistry;
use samuelreichor\coPilot\web\assets\chat\SlideoutAsset;
use yii\base\Event;
use yii\log\FileTarget;

/**
 * CoPilot – AI Agent plugin for Craft CMS.
 *
 * @method static CoPilot getInstance()
 * @method Settings getSettings()
 * @property-read AgentService $agentService
 * @property-read AuditService $auditService
 * @property-read ContextService $contextService
 * @property-read ConversationService $conversationService
 * @property-read FieldNormalizer $fieldNormalizer
 * @property-read PermissionGuard $permissionGuard
 * @property-read ProviderService $providerService
 * @property-read SchemaService $schemaService
 * @property-read SystemPromptBuilder $systemPromptBuilder
 * @property-read TransformerRegistry $transformerRegistry
 * @author Samuel Reichör <samuelreichor@gmail.com>
 * @copyright Samuel Reichör
 * @license https://craftcms.github.io/license/ Craft License
 */
class CoPilot extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'agentService' => AgentService::class,
                'auditService' => AuditService::class,
                'contextService' => ContextService::class,
                'conversationService' => ConversationService::class,
                'fieldNormalizer' => FieldNormalizer::class,
                'permissionGuard' => PermissionGuard::class,
                'providerService' => ProviderService::class,
                'schemaService' => SchemaService::class,
                'systemPromptBuilder' => SystemPromptBuilder::class,
                'transformerRegistry' => TransformerRegistry::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();
        $this->attachEventHandlers();

        Craft::$app->onInit(function() {
            $this->registerCpResources();
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return null;
        }

        $pages = [];

        if ($user->can(Constants::PERMISSION_VIEW_CHAT)) {
            $pages['chat'] = ['label' => 'Chat', 'url' => 'co-pilot'];
        }

        if ($user->can(Constants::PERMISSION_VIEW_BRAND_VOICE)) {
            $pages['brand-voice'] = ['label' => 'Brand Voice', 'url' => 'co-pilot/brand-voice'];
        }

        if ($user->can(Constants::PERMISSION_VIEW_AUDIT_LOG)) {
            $pages['audit-log'] = ['label' => 'Audit Log', 'url' => 'co-pilot/audit-log'];
        }

        if (count($pages) === 0) {
            return null;
        }

        $item['label'] = 'CoPilot';

        if (count($pages) === 1) {
            $firstPage = reset($pages);
            $item['url'] = $firstPage['url'];

            return $item;
        }

        $item['subnav'] = $pages;

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('co-pilot/settings'));
    }

    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();

        $this->schemaService->invalidateCache();
    }

    private function registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new FileTarget([
            'logFile' => '@storage/logs/co-pilot.log',
            'categories' => ['co-pilot'],
            'logVars' => [],
        ]);
    }

    private function attachEventHandlers(): void
    {
        $this->registerCpUrlRules();
        $this->registerPermissions();
        $this->registerEntryContextInjection();
        $this->registerGarbageCollection();
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function(): void {
            $this->auditService->purgeOldLogs();
        });
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['co-pilot'] = 'co-pilot/chat/index';
                $event->rules['co-pilot/<conversationId:\d+>'] = 'co-pilot/chat/index';
                $event->rules['co-pilot/settings'] = 'co-pilot/settings/index';
                $event->rules['co-pilot/brand-voice'] = 'co-pilot/settings/brand-voice';
                $event->rules['co-pilot/audit-log'] = 'co-pilot/audit-log/index';
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'CoPilot',
                    'permissions' => [
                        Constants::PERMISSION_VIEW_CHAT => [
                            'label' => 'View Chat',
                            'nested' => [
                                Constants::PERMISSION_DELETE_CHAT => [
                                    'label' => 'Delete Chat',
                                ],
                                Constants::PERMISSION_CREATE_CHAT => [
                                    'label' => 'Create Chats',
                                ],
                                Constants::PERMISSION_VIEW_OTHER_USERS_CHATS => [
                                    'label' => 'View Chats Created by Other Users',
                                    'nested' => [
                                        Constants::PERMISSION_EDIT_OTHER_USERS_CHATS => [
                                            'label' => 'Edit Chats Created by Other Users',
                                        ],
                                        Constants::PERMISSION_DELETE_OTHER_USERS_CHATS => [
                                            'label' => 'Delete Chats Created by Other Users',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        Constants::PERMISSION_VIEW_BRAND_VOICE => [
                            'label' => 'View Brand Voice',
                            'nested' => [
                                Constants::PERMISSION_EDIT_BRAND_VOICE => [
                                    'label' => 'Edit Brand Voice',
                                ],
                            ],
                        ],
                        Constants::PERMISSION_VIEW_AUDIT_LOG => [
                            'label' => 'View Audit Logs',
                        ],
                    ],
                ];
            },
        );
    }

    private function registerEntryContextInjection(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
            function(DefineHtmlEvent $event) {
                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }

                $user = Craft::$app->getUser()->getIdentity();
                if (!$user || !$user->can(Constants::PERMISSION_VIEW_CHAT)) {
                    return;
                }

                Craft::$app->getView()->registerAssetBundle(SlideoutAsset::class);

                $icon = $this->providerService->getActiveProvider()->getIcon();

                $event->html .= Craft::$app->getView()->renderTemplate(
                    'co-pilot/_toolbar-trigger',
                    ['entryId' => $entry->id, 'icon' => $icon],
                );
            },
        );
    }

    private function registerCpResources(): void
    {
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can(Constants::PERMISSION_VIEW_CHAT)) {
            return;
        }

        Craft::$app->getView()->registerJs(
            'window.coPilotConfig = ' . json_encode([
                'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
                'csrfTokenValue' => Craft::$app->getRequest()->getCsrfToken(),
                'actionUrl' => '/actions/co-pilot/chat/send',
            ]),
            View::POS_HEAD,
        );
    }
}
