<?php
namespace PacketEventManager;

use AbilityManager\AbilityManager;
use Core\Core;
use Core\util\Util;
use Equipments\Equipments;
use EtcItem\EtcItem;
use GuildManager\GuildManager;
use HotbarSystemManager\HotbarSystemManager;
use leeapp\npcManager\NPCManager;
use Monster\Monster;
use PartyManager\PartyManager;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\{Armor, Durable, Tool};
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\level\particle\DustParticle;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\types\WindowTypes;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use PrefixManager\PrefixManager;
use QuestManager\QuestManager;
use ServerLogManager\ServerLogManager;
use SkillManager\SkillManager;
use UiLibrary\UiLibrary;

class PacketEventManager extends PluginBase {

    public const MAIN_BGM = 177;
    private static $instance = null;
    public $hit = [];

    public static function getInstance() {
        return self::$instance;
    }

    public function onLoad() {
        self::$instance = $this;
    }

    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
        $this->Guild = GuildManager::getInstance();
        $this->Party = PartyManager::getInstance();
        $this->Prefix = PrefixManager::getInstance();
        $this->HotbarSystem = HotbarSystemManager::getInstance();
        $this->Equipments = Equipments::getInstance();
        $this->quest = QuestManager::getInstance();
        $this->monster = Monster::getInstance();
        $this->npc = NPCManager::getInstance();
        $this->ui = UiLibrary::getInstance();
        $this->core = Core::getInstance();
        $this->util = new Util($this->core);
        $this->monster = Monster::getInstance();
        $this->ability = AbilityManager::getInstance();
        $this->etcitem = EtcItem::getInstance();
        $this->skill = SkillManager::getInstance();
        $this->serverlog = ServerLogManager::getInstance();
    }

    public function setTag(Player $player) {
        $name = $player->getName();
        if ($this->Guild->isGuild($name)) {
            $pre = $this->Prefix->getPrefix($name);
            $guild = $this->Guild->getGuild($name);
            $tag = "{$pre} §r§f| {$guild} §r§f| {$name}";
            $player->setNameTag($tag);
        } elseif (!$this->Guild->isGuild($name)) {
            $pre = $this->Prefix->getPrefix($name);
            $tag = "{$pre} §r§f| {$name}";
            $player->setNameTag($tag);
        }
    }

    public function setTag_1(Player $player) {
        $name = $player->getName();
        $pre = $this->Prefix->getPrefix($name);
        $guild = $this->Guild->getGuild($name);
        $tag = "{$pre} §r§f| {$guild} §r§f| {$name}";
        $player->setNameTag($tag);
    }

    public function setTag_2(Player $player) {
        $name = $player->getName();
        $pre = $this->Prefix->getPrefix($name);
        $tag = "{$pre} §r§f| {$name}";
        $player->setNameTag($tag);
    }

    public function setNameTag(Entity $entity) {
        $this->getScheduler()->scheduleDelayedTask(
                new class($this, $entity) extends Task {
                    public function __construct(PacketEventManager $plugin, Entity $entity) {
                        $this->plugin = $plugin;
                        $this->entity = $entity;
                    }

                    public function onRun($currentTick) {
                        $tag = explode("\n", $this->entity->getNameTag());
                        $this->entity->setNameTag("{$tag[0]}\n{$this->plugin->HealthBar($this->entity)}");
                    }
                }, 1);
    }

    public function HealthBar(Entity $entity) {
        $maxhp = $entity->getMaxHealth();
        $hp = $entity->getHealth();
        $o = $maxhp / 32;
        if ($maxhp == $hp) {
            $a = str_repeat("§c⎪§r", round($maxhp / $o));
            return $a;
        } elseif ($maxhp - $hp > 0) {
            $a = str_repeat("§c⎪§r", round($hp / $o)) . str_repeat("§0⎪§r", round($maxhp / $o - $hp / $o));
            return $a;
        }
    }

    public function Notice(Player $player) {
        if ($this->ability->isAbility($player->getName())) {
            $form = $this->ui->CustomForm(function (Player $player, array $data) {
            });
            $form->setTitle("Tele Server :: RPG");
            $patch = [
                    "  §f- 더이상 무기의 내구도가 닳지 않습니다.",
                    "  §f- 더이상 무기가 아닌 아이템으로 공격이 불가능합니다.",
                    "  §f- 스코어 보드가 추가되었습니다."
            ];
            $msg = "";
            for ($i = 0; $i < count($patch); $i++) {
                $msg .= "\n" . $patch[$i];
            }
            $form->addLabel("§c▶ §f디스플레이 설정에서 UI를 클래식으로 변경하여주세요.\n  아테나 서버는 클래식 UI를 사용해야 더욱 편이해집니다.");
            $form->addLabel("§6▶ §f서버 패치노트{$msg}");
            $form->sendToPlayer($player);
        }
    }

    public function fixWeapon($damager) {
        if ($damager instanceof Player) {
            if ($damager->getInventory()->getIteminHand() instanceof Durable && $damager->getInventory()->getIteminHand()->isUnbreakable())
                return;
            $this->getScheduler()->scheduleDelayedTask(
                    new class($this, $damager) extends Task {
                        public function __construct(PacketEventManager $plugin, $damager) {
                            $this->plugin = $plugin;
                            $this->damager = $damager;
                        }

                        public function onRun($currentTick) {
                            if (!$this->damager instanceof Player || $this->damager->getInventory() == null || $this->damager->getInventory()->getIteminHand() == null || $this->damager->getInventory()->getIteminHand() instanceof ItemBlock || !$this->damager->getInventory()->getIteminHand() instanceof Tool)
                                return;
                            $this->damager->getInventory()->setIteminHand($this->damager->getInventory()->getIteminHand()->setDamage(0));
                        }
                    }, 1);
        }
    }

    public function fixArmor($target) {
        if ($target instanceof Player) {
            $this->getScheduler()->scheduleDelayedTask(
                    new class($this, $target) extends Task {
                        public function __construct(PacketEventManager $plugin, $target) {
                            $this->plugin = $plugin;
                            $this->target = $target;
                        }

                        public function onRun($currentTick) {
                            for ($i = 0; $i < 4; $i++) {
                                if (!$this->target instanceof Player || $this->target->getInventory() == null || $this->target->getInventory()->getIteminHand() == null || $this->target->getArmorInventory()->getItem($i) instanceof ItemBlock || !$this->target->getArmorInventory()->getItem($i) instanceof Armor)
                                    continue;
                                $this->target->getArmorInventory()->setItem($i, $this->target->getArmorInventory()->getItem($i)->setDamage(0));
                            }
                        }
                    }, 1);
        }
    }

    public function getPlayerDamage($player, $target) {
        $name = $player->getName();
        if ($this->util->getJob($player->getName()) == "모험가" or $this->util->getJob($player->getName()) == "나이트") {
            $CA = $this->Equipments->getATK($player) + $this->util->getATK($player->getName());
        } elseif ($this->util->getJob($player->getName()) == "아처") {
            $CA = ($this->Equipments->getATK($player)) / 2 + $this->util->getATK($player->getName());
        } elseif ($this->util->getJob($player->getName()) == "위자드" or $this->util->getJob($player->getName()) == "프리스트") {
            (float) $CA = (float) ($this->Equipments->getMATK($player)) / 5 + (float) $this->util->getMATK($player->getName());
        }
        $CA *= $this->etcitem->getATKBuff($player->getName());
        $M_DEF = $target->getDEF();
        $AN = $this->util->getAttack($name);
        $CD = $this->util->getCD($player->getName());
        $DR = 0; // 상대 데미지 감소량
        $damage = ($CA - $M_DEF) * (100 - $DR) * $AN / 75;
        if (mt_rand(0, 100) / 100 <= $this->util->getCritical($player->getName()) / 100) {
            $damage += $CD;
            $player->sendPopup("§l§c크리티컬!");
        }
        return $damage;
    }

    public function getMonsterDamage($player, $target) {
        $CA = $target->getATK();
        $CD = 200;
        if ($this->util->getJob($player->getName()) == "나이트" and $this->util->getJob($player->getName()) == "아처") $M_DEF = ($this->util->getDEF($player->getName()) + $this->Equipments->getDEF($player));
        else $M_DEF = ($this->util->getMDEF($player->getName()) + $this->Equipments->getMDEF($player));
        $M_DEF *= $this->etcitem->getDEFBuff($player->getName());
        $DR = 0;
        $AN = 1;
        $damage = ($CA - $M_DEF) * (100 - $DR) * $AN / 100;
        return $damage;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() == "as") {
            $this->test($sender);
            return true;
        }
        return true;
    }

    public function test(Player $player) {

        $form = $this->ui->LongForm(function (Player $player, array $data) {
        });
        $form->setTitle("Tele Server :: RPG");
        $form->sendToPlayer($player);
    }
}
