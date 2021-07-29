<?php

namespace mutation\translate;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\i18n\I18N;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use GraphQL\Type\Definition\Type;
use mutation\translate\arguments\StaticMessageArguments;
use mutation\translate\models\Settings;
use mutation\translate\models\SourceMessage;
use mutation\translate\interfaces\StaticMessageInterface;
use mutation\translate\resolvers\StaticMessageResolver;
use yii\base\Event;
use yii\i18n\MessageSource;
use yii\i18n\MissingTranslationEvent;

/**
 * Class Translate
 * @package mutation\translate
 * @property \mutation\translate\services\SourceMessage $sourceMessage
 * @property Settings $settings
 */
class Translate extends Plugin
{
    public const EXPORT_TRANSLATIONS_PERMISSION = 'exportTranslations';
    public const IMPORT_TRANSLATIONS_PERMISSION = 'importTranslations';
    public const TRANSLATIONS_UTILITIES_PERMISSION = 'translationsUtilities';
    public const CHANGE_TRANSLATIONS_SETTINGS_PERMISSION = 'changeTranslationsSettings';

    public function init()
    {
        parent::init();

        $this->name = $this->settings->pluginName;

        $this->setComponents(
            [
                'sourceMessage' => services\SourceMessage::class,
            ]
        );

        $this->initDbMessages();
        $this->initPermissions();
        $this->initCpUrls();
        $this->initAddMissingTranslations();
        $this->initGraphqlSupport();
    }

    public function getCpNavItem()
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        $general = Craft::$app->getConfig()->getGeneral();

        $item = parent::getCpNavItem();
        $item['subnav']['translations'] = ['label' => 'Messages', 'url' => 'translations-admin'];
        if ($currentUser->can(self::EXPORT_TRANSLATIONS_PERMISSION)) {
            $item['subnav']['export'] = ['label' => 'Export', 'url' => 'translations-admin/export-messages'];
        }
        if ($currentUser->can(self::IMPORT_TRANSLATIONS_PERMISSION)) {
            $item['subnav']['import'] = ['label' => 'Import', 'url' => 'translations-admin/import-messages'];
        }
        if ($currentUser->can(self::TRANSLATIONS_UTILITIES_PERMISSION)) {
            $item['subnav']['utilities'] = [
                'label' => 'Utilities',
                'url' => 'translations-admin/translations-utilities'
            ];
        }
        if ($currentUser->can(self::CHANGE_TRANSLATIONS_SETTINGS_PERMISSION) && $general->allowAdminChanges) {
            $item['subnav']['settings'] = ['label' => 'Settings', 'url' => 'translations-admin/plugin-settings'];
        }
        return $item;
    }

    protected function createSettingsModel()
    {
        return new Settings();
    }

    public function getSettingsResponse()
    {
        Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('translations-admin/plugin-settings'));
    }

    private function initDbMessages()
    {
        /** @var I18N $i18n */
        $i18n = Craft::$app->getComponents(false)['i18n'];

        foreach ($this->settings->getCategories() as $category) {
            $i18n->translations[$category] = [
                'class' => DbMessageSource::class,
                'sourceLanguage' => Craft::$app->getSites()->getPrimarySite()->language,
                'forceTranslation' => true,
            ];
        }

        Craft::$app->setComponents(
            [
                'i18n' => $i18n
            ]
        );
    }

    private function initPermissions()
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $label = Craft::t('translations-admin', 'Translations admin');
                $event->permissions[$label] = [
                    self::EXPORT_TRANSLATIONS_PERMISSION => [
                        'label' => 'Export translations',
                    ],
                    self::IMPORT_TRANSLATIONS_PERMISSION => [
                        'label' => 'Import translations',
                    ],
                    self::TRANSLATIONS_UTILITIES_PERMISSION => [
                        'label' => 'Use translations utilities',
                    ],
                    self::CHANGE_TRANSLATIONS_SETTINGS_PERMISSION => [
                        'label' => 'Change translations settings',
                    ]
                ];
            }
        );
    }

    private function initCpUrls()
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['translations-admin'] = 'translations-admin/messages/index';
                foreach ($this->settings->getCategories() as $category) {
                    $event->rules["translations-admin/<category:$category>"] = 'translations-admin/messages/index';
                }
                $event->rules['translations-admin/export-messages'] = 'translations-admin/export/index';
                $event->rules['translations-admin/import-messages'] = 'translations-admin/import/index';
                $event->rules['translations-admin/translations-utilities'] = 'translations-admin/utilities/index';
                $event->rules['translations-admin/plugin-settings'] = 'translations-admin/settings/index';
            }
        );
    }

    private function initAddMissingTranslations()
    {
        Event::on(
            MessageSource::class,
            MessageSource::EVENT_MISSING_TRANSLATION,
            function (MissingTranslationEvent $event) {
                if (!$this->settings->addMissingTranslations) {
                    return;
                }

                if (!Craft::$app->request->isSiteRequest && $this->settings->addMissingSiteRequestOnly) {
                    return;
                }

                if (!$event->message) {
                    return;
                }

                if (!in_array($event->category, $this->settings->getCategories(), true)) {
                    return;
                }

                $sourceMessage = SourceMessage::find()
                    ->where(array((Craft::$app->db->getIsMysql() ? 'BINARY(`message`)' : 'message') => $event->message, 'category' => $event->category))
                    ->one();

                if (!$sourceMessage) {
                    $sourceMessage = new SourceMessage();
                    $sourceMessage->category = $event->category;
                    $sourceMessage->message = $event->message;
                    $sourceMessage->save();
                }
            }
        );
    }

    private function initGraphqlSupport()
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            function (RegisterGqlTypesEvent $event) {
                $event->types[] = StaticMessageInterface::class;
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function (RegisterGqlQueriesEvent $event) {
                $event->queries['staticMessages'] =
                    [
                        'type' => Type::listOf(StaticMessageInterface::getType()),
                        'args' => StaticMessageArguments::getArguments(),
                        'resolve' => StaticMessageResolver::class . '::resolve',
                    ];
            }
        );
    }
}
