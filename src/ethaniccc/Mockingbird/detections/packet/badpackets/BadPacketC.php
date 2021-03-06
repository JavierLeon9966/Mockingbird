<?php

namespace ethaniccc\Mockingbird\detections\packet\badpackets;

use ethaniccc\Mockingbird\detections\Detection;
use ethaniccc\Mockingbird\user\User;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;

/**
 * Class BadPacketC
 * @package ethaniccc\Mockingbird\detections\packet\badpackets
 * BadPacketC checks if the user is hitting.. themselves. This type of BS
 * is used in some fly bypasses.
 */
class BadPacketC extends Detection{

    public function __construct(string $name, ?array $settings){
        parent::__construct($name, $settings);
    }

    public function handleReceive(DataPacket $packet, User $user): void{
        if($packet instanceof InventoryTransactionPacket && $packet->trData->getTypeId() === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY && $packet->trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK){
            $targetEntity = $packet->trData->getEntityRuntimeId();
            if($user->player->getId() === $targetEntity){
                $this->fail($user, "id={$user->player->getId()} attackedId=$targetEntity");
            }
        }
    }

}