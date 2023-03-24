<?php

namespace dhnnz\TopVoteNPC\entities;

use dhnnz\TopVoteNPC\Loader;
use pocketmine\entity\Human;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\player\Player;

class TopVoteEntity extends Human {

    public function attack(EntityDamageEvent $source): void
    {
        $entity = $source->getEntity();
        if (!($entity instanceof TopVoteEntity) || !($source instanceof EntityDamageByEntityEvent)) {
            return;
        }

        $damager = $source->getDamager();
        if (!($damager instanceof Player)) {
            return;
        }

        $plugin = Loader::getInstance();
        if (!isset($plugin->setup[$damager->getName()])) {
            $source->cancel();
            return;
        }

        $entity->close();
        $damager->sendMessage("Successfully removed " . $entity->getName());
        unset($plugin->setup[$damager->getName()]);
    }
}