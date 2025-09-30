<?php

declare(strict_types=1);

namespace artkolev\yandex_delivery\DTO;

use OpenApi\Attributes\Property;
use OpenApi\Attributes\Schema;
use palax\onec_catalog\core\order\model\Order;
use palax\onec_catalog\core\user\interface\UserModelInterface;

#[Schema(schema: 'yandex-order-dto')]
class YandexOfferDTO extends DTO
{

    #[Property]
    public readonly Order $order;
    #[Property]
    public readonly UserModelInterface $user;
    #[Property]
    public readonly string $source_platform_id;
    #[Property]
    public readonly string $address;
    #[Property]
    public readonly string $room;

    public function __construct(Order $order, UserModelInterface $user, $source_platform_id, $address, $room)
    {
        $this->order = $order;
        $this->user = $user;
        $this->source_platform_id = $source_platform_id;
        $this->address = $address;
        $this->room = $room;
    }

    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'user' => $this->user,
            'source_platform_id' => $this->source_platform_id,
            'address' => $this->address,
            'room' => $this->room,
        ];
    }
}