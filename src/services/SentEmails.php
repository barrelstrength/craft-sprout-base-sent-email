<?php
/**
 * @link      https://sprout.barrelstrengthdesign.com
 * @copyright Copyright (c) Barrel Strength Design LLC
 * @license   https://craftcms.github.io/license
 */

namespace barrelstrength\sproutbasesentemail\services;

use barrelstrength\sproutbase\jobs\PurgeElements;
use barrelstrength\sproutbase\SproutBase;
use barrelstrength\sproutbasesentemail\elements\SentEmail;
use barrelstrength\sproutbasesentemail\models\SentEmailInfoTable;
use barrelstrength\sproutbasesentemail\SproutBaseSentEmail;
use Craft;
use craft\base\Component;
use craft\base\Plugin;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\helpers\Json;
use craft\mail\Mailer as CraftMailer;
use craft\mail\Message;
use craft\mail\transportadapters\BaseTransportAdapter;
use craft\mail\transportadapters\Smtp;
use Exception;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\mail\MailEvent;

/**
 * Class SentEmails
 *
 * @package barrelstrength\sproutbasesentemail\services
 *
 * @property string $craftVersion
 */
class SentEmails extends Component
{
    /**
     * Default limit for the Sent Email trimming
     */
    const SENT_EMAIL_DEFAULT_LIMIT = 5000;

    /**
     * The name of the variable used for the Email Message variables where we pass sent email info.
     */
    const SENT_EMAIL_MESSAGE_VARIABLE = 'sprout-sent-email-info';

    /**
     * @param MailEvent $event
     *
     * @return null
     * @throws Throwable
     */
    public function logSentEmail(MailEvent $event)
    {
        /**
         * @var $message Message
         */
        $message = $event->message;

        $from = $message->getFrom();
        $fromEmail = '';
        $fromName = '';
        if ($from) {
            $fromEmail = ($res = array_keys($from)) ? $res[0] : '';
            $fromName = ($res = array_values($from)) ? $res[0] : '';
        }

        // We manage the info we track using the Message variables
        $variables = $message->variables;

        /**
         * @var $infoTable SentEmailInfoTable
         */
        $infoTable = $variables[self::SENT_EMAIL_MESSAGE_VARIABLE] ?? null;

        // Populate what we can for the Info Table for System Messages
        if (isset($message->mailer) && get_class($message->mailer) === CraftMailer::class) {
            $infoTable = new SentEmailInfoTable();
            $infoTable = $this->updateInfoTableWithCraftInfo($message, $infoTable);
        }

        // Kill saving of info table on other sending modules
        if ($infoTable === null) {
            return null;
        }
        // Sender Info
        $infoTable->senderName = $fromName;
        $infoTable->senderEmail = $fromEmail;

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $infoTable->ipAddress = 'Console Request';
            $infoTable->userAgent = 'Console Request';
        } else {
            $infoTable->ipAddress = Craft::$app->getRequest()->getUserIP();
            $infoTable->userAgent = Craft::$app->getRequest()->getUserAgent();
        }

        $emailSettings = App::mailSettings();

        /** @var BaseTransportAdapter $transportType */
        $transportType = new $emailSettings->transportType();
        $transportType->setAttributes($emailSettings->transportSettings);
        $infoTable->transportType = $transportType::displayName();

        // If SMTP
        if (get_class($transportType) === Smtp::class) {
            /** @var Smtp $transportType */
            $infoTable->host = $transportType->host;
            $infoTable->port = $transportType->port;
            $infoTable->username = $transportType->username;
            $infoTable->encryptionMethod = $transportType->encryptionMethod;
            $infoTable->timeout = $transportType->timeout;
        }

        $deliveryStatuses = $infoTable->getDeliveryStatuses();

        if ($event->isSuccessful) {
            $infoTable->deliveryStatus = $deliveryStatuses['Sent'];
        } else {
            $infoTable->deliveryStatus = $deliveryStatuses['Error'];

            // @todo - improve flow so we can give a better error message
            $infoTable->message = Craft::t('sprout-base-sent-email', 'Unable to send message.');
        }

        $this->saveSentEmail($message, $infoTable);

        return true;
    }

    /**
     * @param Event $event
     *
     * @throws Throwable
     */
    public function logSentEmailCampaign(Event $event)
    {
        // Prepare some variables
        // -----------------------------------------------------------

        $emailModel = $event['emailModel'];
        $campaign = $event['campaign'];

        // If we have info set, grab the custom info that's already prepared
        // If we don't have info, we probably have an email sent by Craft so
        // we can continue with our generic info table model
        $infoTable = new SentEmailInfoTable();

        // Prepare our info table settings for Campaigns
        // -----------------------------------------------------------

        // General Info
        $emailTypes = $infoTable->getEmailTypes();
        $infoTable->emailType = $emailTypes['Campaign'];

        // Sender Info
        $infoTable->senderName = $emailModel->fromName;
        $infoTable->senderEmail = $emailModel->fromEmail;

        // Email Settings
        $infoTable->mailer = ucwords($campaign->mailer);

        $this->saveSentEmail($emailModel, $infoTable);
    }

    /**
     * Save email snapshot using the Sent Email Element Type
     *
     * @param Message            $message
     * @param SentEmailInfoTable $infoTable
     *
     * @return SentEmail|bool
     * @throws Throwable
     */
    public function saveSentEmail(Message $message, SentEmailInfoTable $infoTable)
    {
        $from = $message->getFrom();
        $fromEmail = '';
        $fromName = '';
        if ($from) {
            $fromEmail = ($res = array_keys($from)) ? $res[0] : '';
            $fromName = ($res = array_values($from)) ? $res[0] : '';
        }

        $to = $message->getTo();
        $toEmail = '';
        if ($to) {
            $toEmail = ($res = array_keys($to)) ? $res[0] : '';
        }

        $sentEmailSettings = SproutBaseSentEmail::$app->settings->getSentEmailSettings();

        if (!$sentEmailSettings->enableSentEmails) {
            return false;
        }

        $this->cleanUpSentEmails();


        // decode subject if it is encoded
        $isEncoded = preg_match("/=\?UTF-8\?B\?(.*)\?=/", $message->getSubject(), $matches);

        if ($isEncoded) {
            $encodedString = $matches[1];
            $subject = base64_decode($encodedString);
        } else {
            $subject = $message->getSubject();
        }

        $sentEmail = new SentEmail();

        $sentEmail->title = $subject;
        $sentEmail->emailSubject = $subject;
        $sentEmail->fromEmail = $fromEmail;
        $sentEmail->fromName = $fromName;
        $sentEmail->toEmail = $toEmail;

        $body = $message->getSwiftMessage()->getBody();
        $children = $message->getSwiftMessage()->getChildren();

        if ($body) {
            $sentEmail->htmlBody = $body;
            $sentEmail->body = $body;
        } else if ($children) {
            foreach ($children as $child) {
                if ($child->getContentType() == 'text/html') {
                    $sentEmail->htmlBody = $child->getBody();
                }

                if ($child->getContentType() == 'text/plain') {
                    $sentEmail->body = $child->getBody();
                }
            }
        }

        $deliveryStatuses = $infoTable->getDeliveryStatuses();

        if ($infoTable->deliveryStatus == $deliveryStatuses['Error']) {
            $sentEmail->status = 'failed';
        }

        // Remove deliveryStatus as we can determine it from the status in the future
        $infoTable = $infoTable->getAttributes();
        unset($infoTable['deliveryStatus']);
        $sentEmail->info = Json::encode($infoTable);

        try {
            if (Craft::$app->getElements()->saveElement($sentEmail)) {
                return $sentEmail;
            }
        } catch (Exception $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }

        return false;
    }

    /**
     * @param bool $force
     *
     * @return bool
     * @throws SiteNotFoundException
     */
    public function cleanUpSentEmails($force = false): bool
    {
        $sentEmailSettings = SproutBaseSentEmail::$app->settings->getSentEmailSettings();

        $probability = (int)$sentEmailSettings->cleanupProbability;

        // See Craft Garbage collection treatment of probability
        // https://docs.craftcms.com/v3/gc.html
        /** @noinspection RandomApiMigrationInspection */
        if (!$force && mt_rand(0, 1000000) >= $probability) {
            return false;
        }

        // Default to 5000 if no integer is found in settings
        $sentEmailsLimit = is_int((int)$sentEmailSettings->sentEmailsLimit)
            ? (int)$sentEmailSettings->sentEmailsLimit
            : static::SENT_EMAIL_DEFAULT_LIMIT;

        if ($sentEmailsLimit <= 0) {
            return false;
        }

        $ids = SentEmail::find()
            ->limit(null)
            ->offset($sentEmailsLimit)
            ->orderBy(['sproutemail_sentemail.dateCreated' => SORT_DESC])
            ->anyStatus()
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->ids();

        $purgeElements = new PurgeElements();
        $purgeElements->elementType = SentEmail::class;
        $purgeElements->idsToDelete = $ids;

        SproutBase::$app->utilities->purgeElements($purgeElements);

        return true;
    }

    /**
     * Create a SproutEmail_SentEmailInfoTableModel with the Craft and plugin info values
     *
     * @param       $pluginHandle
     * @param array $values
     *
     * @return SentEmailInfoTable
     */
    public function createInfoTableModel($pluginHandle, array $values = []): SentEmailInfoTable
    {
        $infoTable = new SentEmailInfoTable();
        $infoTable->setAttributes($values, false);

        /**
         * @var $plugin Plugin
         */
        $plugin = Craft::$app->getPlugins()->getPlugin($pluginHandle);
        $infoTable->source = '';
        $infoTable->sourceVersion = '';

        if ($plugin) {
            $infoTable->source = $plugin::getInstance()->name;
            $infoTable->sourceVersion = $plugin::getInstance()->name.' '.$plugin->getVersion();
        }

        $craftVersion = $this->getCraftVersion();

        $infoTable->craftVersion = $craftVersion;

        return $infoTable;
    }

//    public function getDeliveryStatus($status)
//    {
//        switch ($status) {
//            case 'Live':
//                return Craft::t('sprout-base-sent-email', 'Live');
//                break;
//            case 'Test':
//                return Craft::t('sprout-base-sent-email', 'Test');
//                break;
//        }
//    }

    /**
     * Update the SproutEmail_SentEmailInfoTableModel based on the emailKey
     *
     * @param Message            $message
     * @param SentEmailInfoTable $infoTable
     *
     * @return SentEmailInfoTable
     */
    public function updateInfoTableWithCraftInfo(Message $message, SentEmailInfoTable $infoTable): SentEmailInfoTable
    {
        $craftVersion = $this->getCraftVersion();

        $emailTypes = $infoTable->getEmailTypes();
        $infoTable->emailType = $emailTypes['System'];
        $infoTable->source = 'Craft CMS';
        $infoTable->sourceVersion = $craftVersion;
        $infoTable->craftVersion = $craftVersion;

        if ($message->key === 'test_email') {
            $deliveryTypes = $infoTable->getDeliveryTypes();
            $infoTable->deliveryType = $deliveryTypes['Test'];
        }

        $infoTable->mailer = Craft::t('sprout-base-sent-email', 'Craft Mailer');

        return $infoTable;
    }

    /**
     * Render our HTML and Text email templates like Craft does
     *
     * Craft provides us the original email model, so we need to process the
     * HTML and Text body fields again in order to store the email content as
     * it was sent.
     *
     * @param $emailModel
     * @param $variables
     *
     * @return mixed
     * @throws LoaderError
     * @throws SyntaxError
     */
    protected function renderEmailContentLikeCraft($emailModel, $variables)
    {
        if ($emailModel->htmlBody) {
            $renderedHtmlBody = Craft::$app->getView()->renderString($emailModel->htmlBody, $variables);
            $renderedTextBody = Craft::$app->getView()->renderString($emailModel->body, $variables);
        } else {
            $renderedHtmlBody = Craft::$app->getView()->renderString($emailModel->body, $variables);
            $renderedTextBody = Craft::$app->getView()->renderString($emailModel->body, $variables);
        }

        $emailModel->htmlBody = $renderedHtmlBody;
        $emailModel->body = $renderedTextBody;

        return $emailModel;
    }

    /**
     * @return string
     */
    private function getCraftVersion(): string
    {
        $version = Craft::$app->getVersion();
        $craftVersion = '';

        if (version_compare($version, '2.6.2951', '>=')) {
            $craftVersion = 'Craft '.Craft::$app->getEditionName().' '.$version;
        }

        return $craftVersion;
    }
}