<?php

namespace NeuroCheckout\Event;

use Configuration;
use Context;
use Customer;
use CustomerMessage;
use CustomerThread;
use Order;

class SupportEventBuilder
{
    public static function buildFromCustomerMessage(CustomerMessage $message): ?array
    {
        $messageId = (int)($message->id ?? 0);
        $threadId = (int)($message->id_customer_thread ?? 0);
        $messageText = self::normalizeMessageText($message->message ?? null);

        if ($messageId <= 0 || $threadId <= 0 || $messageText === '') {
            return null;
        }

        if (!empty($message->private) || (int)($message->id_employee ?? 0) > 0) {
            return null;
        }

        $thread = new CustomerThread($threadId);
        if (empty($thread->id)) {
            return null;
        }

        $context = Context::getContext();
        $shopId = self::resolveShopId($thread, $context);
        $shopSourceId = self::resolveShopSourceId($context);
        $shopName = self::resolveShopName($context, $shopId);
        $customerId = (int)($thread->id_customer ?? 0);
        $customerEmail = self::resolveCustomerEmail($thread, $customerId);
        $orderId = (int)($thread->id_order ?? 0);
        $orderReference = self::resolveOrderReference($orderId);

        return [
            'event_id' => self::deterministicEventId($shopSourceId, $threadId, $messageId),
            'event_type' => 'support.case_opened',
            'occurred_at' => self::resolveOccurredAt($message),
            'source' => [
                'platform' => 'prestashop',
                'shop_id' => $shopSourceId,
                'shop_name' => $shopName,
            ],
            'support' => [
                'external_case_id' => (string)$threadId,
                'message_id' => (string)$messageId,
                'message_text' => $messageText,
                'channel' => 'form',
                'priority_hint' => 'normal',
                'external_order_id' => $orderId > 0 ? (string)$orderId : null,
                'metadata' => [
                    'prestashop_customer_thread_id' => (string)$threadId,
                    'prestashop_customer_message_id' => (string)$messageId,
                    'prestashop_order_reference' => $orderReference,
                    'ingress_source' => 'prestashop_customer_message_hook',
                ],
            ],
            'customer' => [
                'id' => $customerId > 0 ? (string)$customerId : null,
                'email' => $customerEmail,
            ],
            'order' => [
                'id' => $orderId > 0 ? (string)$orderId : null,
                'reference' => $orderReference,
            ],
        ];
    }

    private static function normalizeMessageText($value): string
    {
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string)$text);
    }

    private static function resolveShopId(CustomerThread $thread, ?Context $context): int
    {
        if (isset($thread->id_shop) && (int)$thread->id_shop > 0) {
            return (int)$thread->id_shop;
        }
        if ($context && isset($context->shop) && $context->shop && (int)$context->shop->id > 0) {
            return (int)$context->shop->id;
        }
        return 0;
    }

    private static function resolveShopSourceId(?Context $context): string
    {
        $shopExternalId = trim((string)Configuration::get('NC_SHOP_EXTERNAL_ID'));
        if ($shopExternalId !== '') {
            return $shopExternalId;
        }
        if ($context && isset($context->shop) && $context->shop && isset($context->shop->id)) {
            return (string)(int)$context->shop->id;
        }
        return '0';
    }

    private static function resolveShopName(?Context $context, int $shopId): ?string
    {
        if ($context && isset($context->shop) && $context->shop && trim((string)$context->shop->name) !== '') {
            return trim((string)$context->shop->name);
        }
        if ($shopId > 0 && class_exists('Shop')) {
            try {
                $shop = new \Shop($shopId);
                if (!empty($shop->name)) {
                    return trim((string)$shop->name);
                }
            } catch (\Throwable $e) {
            }
        }
        return null;
    }

    private static function resolveCustomerEmail(CustomerThread $thread, int $customerId): ?string
    {
        $threadEmail = trim((string)($thread->email ?? ''));
        if ($threadEmail !== '' && filter_var($threadEmail, FILTER_VALIDATE_EMAIL)) {
            return strtolower($threadEmail);
        }
        if ($customerId <= 0) {
            return null;
        }
        try {
            $customer = new Customer($customerId);
            $email = trim((string)($customer->email ?? ''));
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function resolveOrderReference(int $orderId): ?string
    {
        if ($orderId <= 0) {
            return null;
        }
        try {
            $order = new Order($orderId);
            $reference = trim((string)($order->reference ?? ''));
            return $reference !== '' ? $reference : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function resolveOccurredAt(CustomerMessage $message): string
    {
        $dateAdd = trim((string)($message->date_add ?? ''));
        if ($dateAdd !== '') {
            $timestamp = strtotime($dateAdd);
            if ($timestamp !== false) {
                return gmdate('c', $timestamp);
            }
        }
        return gmdate('c');
    }

    private static function deterministicEventId(
        string $shopSourceId,
        int $threadId,
        int $messageId
    ): string {
        $hash = md5('support.message|' . $shopSourceId . '|' . $threadId . '|' . $messageId);
        $timeHi = sprintf('%04x', (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x4000);
        $clockSeq = sprintf('%04x', (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000);

        return substr($hash, 0, 8)
            . '-'
            . substr($hash, 8, 4)
            . '-'
            . $timeHi
            . '-'
            . $clockSeq
            . '-'
            . substr($hash, 20, 12);
    }
}
