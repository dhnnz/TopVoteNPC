<?php

namespace dhnnz\TopVoteNPC;

use dhnnz\TopVoteNPC\entities\TopVoteEntity;
use dhnnz\TopVoteNPC\task\UpdateTask;
use Ifera\ScoreHud\factory\listener\FactoryListener;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\utils\Internet;
use pocketmine\utils\SingletonTrait;

/**
 * Summary of Loader
 */
class Loader extends PluginBase implements Listener
{

    /** @var int */
    const ERR_NO_KEY = 0;

    private static Loader $instance;


    /** @var array */
    public array $setup = [];

    public array $voters = [];

    /**
     * Summary of getVoters
     * @return array
     */
    public function getVoters(): array
    {
        return $this->voters;

    }

    public function onLoad(): void
    {
        self::$instance = $this;
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

    public function onEnable(): void
    {
        $this->saveResource("config.yml");
        $this->saveResource("steve.json");
        $this->saveResource("steve.png");

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        EntityFactory::getInstance()->register(TopVoteEntity::class, function ($world, $nbt): TopVoteEntity {
            return new TopVoteEntity(EntityDataHelper::parseLocation($nbt, $world), Human::parseSkinNBT($nbt), $nbt);
        }, ["TopVoteEntity"]);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->getServer()->getAsyncPool()->submitTask(new UpdateTask($this->getConfig()->get("key")));
            foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
                foreach ($world->getEntities() as $entity) {
                    if ($entity instanceof TopVoteEntity) {
                        $nameTag = $entity->getNameTag();
                        $top = str_starts_with($entity->getNameTag(), "§b#") ? 1 : (str_starts_with($entity->getNameTag(), "§6#") ? 2 : (str_starts_with($entity->getNameTag(), "§a#") ? 3 : 0));
                        if ($top > 0)
                            $this->updateEntity($entity, $top);
                    }
                }
            }
        }), 20);
    }

    /**
     * Summary of onCommand
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if (
            !$sender instanceof Player &&
            $command->getName() !== "topvote" &&
            !$sender->hasPermission("topvotenpc.topvote.cmd") &&
            count($args) < 1
        )
            return false;

        switch ($args[0]) {
            case 'spawn':
            case 'create':
                $count = [1, 2, 3];
                if (!isset($args[1])) {
                    return false;
                }
                $num = intval($args[1]);
                if (!in_array($num, $count)) {
                    $sender->sendMessage("Usage: /topvote spawn " . implode(", ", $count));
                    return false;
                }
                $this->spawnEntity($sender, $num);
                break;
            case 'remove':
            case 'rm':
            case 'delete':
            case 'del':
                if (isset($this->setup[$sender->getName()])) {
                    $sender->sendMessage("You are already in setup mode.");
                    return false;
                }
                $this->setup[$sender->getName()] = true;
                $sender->sendMessage("Click on the NPC to remove it.");
                break;
            default:
                return false;
        }
        return true;
    }

    /**
     * Summary of getTopByPlayer
     * @param string $player
     * @return int
     */
    public function getTopByPlayer(string $player): int
    {
        foreach ($this->getVoters() as $i => $voters) {
            if ($voters["nickname"] == $player) {
                return $i + 1;
            }
        }
        return 0;
    }

    /**
     * Get player by top position.
     * 
     * @param int $top
     * @param string $return
     * @return string|int
     */
    public function getPlayerByTop(int $top, string $return = 'username'): string|int
    {
        $added = isset($this->getVoters()[$top - 1]);

        return match ($return) {
            'username' => $added ? $this->getVoters()[$top - 1]['nickname'] : 'unknown',
            'votes' => $added ? $this->getVoters()[$top - 1]['votes'] : 0,
            default => 'unknown'
        };
    }

    /**
     * Summary of spawnEntity
     * @param Player $player
     * @param int $top
     * @return void
     */
    public function spawnEntity(Player $player, int $top = 1)
    {
        $topPlayerName = $this->getPlayerByTop($top);
        $topVotes = $this->getPlayerByTop($top, "votes");
        $topPlayer = Server::getInstance()->getPlayerExact($topPlayerName);

        $skin = ($topPlayer instanceof Player) ? $topPlayer->getSkin() : $this->defaultSkin();
        $nbt = $this->getNBT($player, $player->getLocation());
        $nametag = match ($top) {
            1 => "§b#1 " . $topPlayerName . " §b- " . (int) $topVotes . " votes",
            2 => "§6#2 " . $topPlayerName . " §b- " . (int) $topVotes . " votes",
            3 => "§a#3 " . $topPlayerName . " §b- " . (int) $topVotes . " votes",
            default => "§cUndefined Ranking"
        };

        $npc = new TopVoteEntity($player->getLocation(), $skin, $nbt);
        $npc->setSkin($skin);
        $npc->setNameTag($nametag);
        $npc->setNameTagAlwaysVisible(true);

        $npc->sendSkin(Server::getInstance()->getOnlinePlayers());
        $npc->spawnToAll();
    }

    /**
     * Summary of updateEntity
     * @param TopVoteEntity $npc
     * @param int $top
     * @return void
     */
    public function updateEntity(TopVoteEntity $npc, int $top = 1)
    {
        $topPlayerName = $this->getPlayerByTop($top);
        $topVotes = $this->getPlayerByTop($top, "votes");

        $server = Server::getInstance();
        $topPlayer = $server->getPlayerExact($topPlayerName);
        $npcNametag = substr($npc->getNameTag(), 6, strpos($npc->getNameTag(), " §b- ") - 6);

        if ($npcNametag == $topPlayerName) {
            $skin = $topPlayer ? $topPlayer->getSkin() : false;
        } else {
            $skin = $topPlayer ? $topPlayer->getSkin() : $this->defaultSkin();
        }

        $nametag = match ($top) {
            1 => "§b#1 " . $topPlayerName . " §b- " . (int) $topVotes . " votes",
            2 => "§6#2 " . $topPlayerName . " §b- " . (int) $topVotes . " votes",
            3 => "§a#3 " . $topPlayerName . " §b- " . (int) $topVotes . " votes",
            default => "§cUndefined Ranking"
        };

        if ($skin)
            $npc->setSkin($skin);
        $npc->setNameTag($nametag);
        $npc->setNameTagAlwaysVisible(true);

        $npc->sendSkin(Server::getInstance()->getOnlinePlayers());
        $npc->spawnToAll();
    }

    /**
     * Summary of defaultSkin
     * @return Skin|null
     */
    public function defaultSkin()
    {
        $Texturefile = "steve.png";
        $geometryFile = "steve.json";
        $geometryIdentifier = "geometry.humanoid.custom";
        $texturePath = $this->getDataFolder() . $Texturefile;
        if (!file_exists($texturePath))
            return null;
        $img = @imagecreatefrompng($texturePath);
        $size = getimagesize($texturePath);
        $skinbytes = "";
        for ($y = 0; $y < $size[1]; $y++) {
            for ($x = 0; $x < $size[0]; $x++) {
                $colorat = @imagecolorat($img, $x, $y);
                $a = ((~((int) ($colorat >> 24))) << 1) & 0xff;
                $r = ($colorat >> 16) & 0xff;
                $g = ($colorat >> 8) & 0xff;
                $b = $colorat & 0xff;
                $skinbytes .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }

        @imagedestroy($img);

        $modelPath = $this->getDataFolder() . $geometryFile;
        $newskin = new Skin("DefaultSkin", $skinbytes, "", $geometryIdentifier, file_get_contents($modelPath));
        return $newskin;
    }

    /**
     * Summary of getNBT
     * @param Player $player
     * @param Location $location
     * @return CompoundTag
     */
    public function getNBT(Player $player, $location)
    {
        $nbt = $this->createBaseNBT($location->asVector3(), null, $location->getYaw(), $location->getPitch());
        $nbt->setTag(
            "Skin", CompoundTag::create()
                ->setString("Name", $player->getSkin()->getSkinId())
                ->setByteArray("Data", $player->getSkin()->getSkinData())
                ->setByteArray("CapeData", $player->getSkin()->getCapeData())
                ->setString("GeometryName", $player->getSkin()->getGeometryName())
                ->setByteArray("GeometryData", $player->getSkin()->getGeometryData())
        );
        return $nbt;
    }

    /**
     * Summary of createBaseNBT
     * @param Vector3 $pos
     * @param Vector3|null $motion
     * @param float $yaw
     * @param float $pitch
     * @return CompoundTag
     */
    public function createBaseNBT(Vector3 $pos, ?Vector3 $motion = null, float $yaw = 0.0, float $pitch = 0.0): CompoundTag
    {
        return CompoundTag::create()
            ->setTag("Pos", new ListTag([
                new DoubleTag($pos->x),
                new DoubleTag($pos->y),
                new DoubleTag($pos->z)
            ]))
            ->setTag("Motion", new ListTag([
                new DoubleTag($motion !== null ? $motion->x : 0.0),
                new DoubleTag($motion !== null ? $motion->y : 0.0),
                new DoubleTag($motion !== null ? $motion->z : 0.0)
            ]))
            ->setTag("Rotation", new ListTag([
                new FloatTag($yaw),
                new FloatTag($pitch)
            ]));
    }
}