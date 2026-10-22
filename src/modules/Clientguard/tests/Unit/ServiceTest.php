<?php

declare(strict_types=1);

use Box\Mod\Client\Event\BeforeAdminClientDeleteEvent;
use Box\Mod\Clientguard\Service;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Order\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use FOSSBilling\InformationException;

use function Tests\Helpers\container;
use function Tests\Helpers\setEntityId;

function clientguardOrder(int $id, string $title, string $status): Order
{
    $order = (new Order())->setTitle($title)->setStatus($status);
    setEntityId($order, $id);

    return $order;
}

function clientguardService(array $orders): Service
{
    $repository = Mockery::mock(OrderRepository::class);
    $repository->shouldReceive('findByClientId')->once()->with(42)->andReturn($orders);

    $entityManager = Mockery::mock(EntityManagerInterface::class);
    $entityManager->shouldReceive('getRepository')->once()->with(Order::class)->andReturn($repository);

    $di = container();
    $di['em'] = $entityManager;

    $service = new Service();
    $service->setDi($di);

    return $service;
}

test('allows deleting a client without orders', function (): void {
    $service = clientguardService([]);

    expect(fn () => $service->blockDeletingClientWithOpenOrders(new BeforeAdminClientDeleteEvent(42)))->not->toThrow(InformationException::class);
});

test('allows deleting a client whose orders are all canceled', function (): void {
    $service = clientguardService([clientguardOrder(7, 'Web Hosting', Order::STATUS_CANCELED)]);

    expect(fn () => $service->blockDeletingClientWithOpenOrders(new BeforeAdminClientDeleteEvent(42)))->not->toThrow(InformationException::class);
});

test('blocks deleting a client with open orders and lists them', function (): void {
    $service = clientguardService([
        clientguardOrder(7, 'Web Hosting', Order::STATUS_ACTIVE),
        clientguardOrder(8, 'example.com', Order::STATUS_CANCELED),
        clientguardOrder(9, 'Addon', Order::STATUS_SUSPENDED),
    ]);

    expect(fn () => $service->blockDeletingClientWithOpenOrders(new BeforeAdminClientDeleteEvent(42)))
        ->toThrow(InformationException::class, '#7 Web Hosting (active), #9 Addon (suspended)');
});
