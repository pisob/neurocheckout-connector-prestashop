<?php

namespace NeuroCheckout\Event;

use Cart;
use Context;
use PrestaShopLogger;
use NeuroCheckout\Infrastructure\EventRepository;

/**
 * CartEventHandler — OPTION B PURE
 *
 * Rôle :
 *  - Enregistrer UNIQUEMENT un snapshot minimal
 *  - Ne jamais construire le payload ici
 *  - Ne jamais recalculer les prix ici
 *  - Ne jamais casser le cycle AJAX panier
 *
 * Le payload réel sera construit dans EventDispatcher
 */
class CartEventHandler
{
    /**
     * Repository (nullable pour sécurité FO)
     */
    private ?EventRepository $repository = null;

    /**
     * Constructeur
     * Injection shop_id courant (multi-shop safe)
     */
    public function __construct()
    {
        try {

            $context = Context::getContext();

            if (!$context || !isset($context->shop) || !$context->shop->id) {
                return;
            }

            $shopId = (int) $context->shop->id;

            if ($shopId <= 0) {
                return;
            }

            $this->repository = new EventRepository($shopId);

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] CartEventHandler constructor error: ' . $e->getMessage(),
                3
            );
        }
    }

    /**
     * handle()
     *
     * Appelé depuis hookActionCartSave
     *
     * IMPORTANT :
     *  - Ne fait AUCUN traitement lourd
     *  - Pas de getProducts()
     *  - Pas de getOrderTotal()
     *  - Pas de Customer load
     *  - Pas de hash
     *
     * Seulement un enregistrement minimal.
     */
    public function handle(Cart $cart): void
    {
        try {

            if (!$this->repository) {
                return;
            }

            if (!$cart->id) {
                return;
            }

            /**
             * Snapshot minimal :
             * - shop_id
             * - cart_id
             * - status = pending
             * - payload vide (temporaire)
             *
             * Le vrai payload sera construit
             * dans EventDispatcher.
             */
            $this->repository->insertMinimal((int) $cart->id);

        } catch (\Throwable $e) {

            // Ultra critique : ne jamais casser AJAX panier
            PrestaShopLogger::addLog(
                '[NC] CartEventHandler handle error: ' . $e->getMessage(),
                3
            );
        }
    }
}
