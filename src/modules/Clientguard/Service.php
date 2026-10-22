<?php

declare(strict_types=1);
/**
 * Client Guard: blocks deleting a client who still has open orders.
 *
 * Deleting a client removes their orders straight from the database without
 * running the order cancel/delete actions, so their hosting accounts and
 * domains would be left running with nothing tracking them. Cancel or delete
 * the orders first (which runs those actions), then delete the client.
 *
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Clientguard;

use Box\Mod\Client\Event\BeforeAdminClientDeleteEvent;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Order\Repository\OrderRepository;
use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * @throws InformationException when the client has orders that are not canceled
     */
    #[AsEventListener]
    public function blockDeletingClientWithOpenOrders(BeforeAdminClientDeleteEvent $event): void
    {
        /** @var OrderRepository $repository */
        $repository = $this->di['em']->getRepository(Order::class);

        $open = array_filter(
            $repository->findByClientId($event->clientId),
            fn (Order $order): bool => $order->getStatus() !== Order::STATUS_CANCELED
        );

        if ($open === []) {
            return;
        }

        $list = implode(', ', array_map(
            fn (Order $order): string => sprintf('#%d %s (%s)', $order->getId(), $order->getTitle(), $order->getStatus()),
            $open
        ));

        throw new InformationException('This client still has open orders: :list. Cancel or delete those orders first (this removes them from the server and registrar), then delete the client.', [':list' => $list]);
    }
}
