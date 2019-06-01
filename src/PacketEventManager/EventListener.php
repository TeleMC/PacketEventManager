<?php
namespace PacketEventManager;

use Monster\mob\MonsterBase;
use Monster\mob\PersonBase;
use pocketmine\entity\DataPropertyManager;
use pocketmine\entity\Entity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\ProjectileHitEntityEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\cheat\PlayerIllegalMoveEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;

class EventListener implements Listener {
    private $plugin;

    public function __construct(PacketEventManager $plugin) {
        $this->plugin = $plugin;
    }

    public function IllegalMove(PlayerIllegalMoveEvent $ev) {
        $ev->setCancelled(true);
    }

    public function onJoin(PlayerJoinEvent $ev) {
        $player = $ev->getPlayer();
        $this->plugin->setTag($player);
        $ev->setJoinMessage(false);
    }

    public function onArrow(ProjectileHitEvent $ev) {
        if ($ev->getEntity() instanceof Arrow) {
            $this->plugin->getScheduler()->scheduleDelayedTask(
                    new class($this->plugin, $ev->getEntity()) extends Task {
                        public function __construct(PacketEventManager $plugin, Arrow $arrow) {
                            $this->plugin = $plugin;
                            $this->arrow = $arrow;
                        }

                        public function onRun($currentTick) {
                            $this->arrow->close();
                        }
                    }, 2);
        }
    }

    public function onQuit(PlayerQuitEvent $ev) {
        $ev->setQuitMessage(false);
    }

    public function onChat(PlayerChatEvent $ev) {
        if ($ev->isCancelled()) return;
        $player = $ev->getPlayer();
        $name = $player->getName();
        $msg = $ev->getMessage();
        if ($this->plugin->Party->getChatMode($name) == true) {
            $position = $this->plugin->Party->getPosition($name);
            $party = $this->plugin->Party->getParty($name);
            foreach ($this->plugin->Party->pdata[$party]["파티원"] as $members) {
                if (Server::getInstance()->getPlayer($members) instanceof Player) {
                    Server::getInstance()->getPlayer($members)->sendMessage("§b• {$name} > {$msg}");
                }
            }
            Server::getInstance()->getLogger()->info("§b• {$position} | {$name} > {$msg}");
            $this->plugin->serverlog->addChatCommandLog($player->getName(), $ev->getMessage(), 1, "파티");
            $ev->setCancelled(true);
        }
        if ($this->plugin->Guild->getChatMode($name) == true) {
            $position = $this->plugin->Guild->getPosition($name);
            $Guild = $this->plugin->Guild->getGuild($name);
            foreach ($this->plugin->Guild->gdata[$Guild]["전체길드원"] as $members) {
                if (Server::getInstance()->getPlayer($members) instanceof Player) {
                    Server::getInstance()->getPlayer($members)->sendMessage("§6• {$position} | {$name} > {$msg}");
                }
            }
            Server::getInstance()->getLogger()->info("§6• {$position} | {$name} > {$msg}");
            $this->plugin->serverlog->addChatCommandLog($player->getName(), $ev->getMessage(), 1, "길드");
            $ev->setCancelled(true);
        }
        if ($this->plugin->Party->getChatMode($name) !== true && $this->plugin->Guild->getChatMode($name) !== true) {
            $prefix = $this->plugin->Prefix->getPrefix($name);
            //$ev->setFormat("{$prefix} §r§f{$name} > {$msg}");
            $ev->setFormat("• {$name} > {$msg}");
            $this->plugin->serverlog->addChatCommandLog($player->getName(), $ev->getMessage(), 1, "전체");
            return;
        }
    }

    public function onDamage(EntityDamageEvent $ev) {
        if ($ev->getEntity() instanceof Player) {
            if ($ev->getEntity()->getGamemode() == 1 || $ev->getEntity()->getGamemode() == 3)
                $ev->getEntity()->sendTip("\n\n\n\n\n\n\n\n" . $this->plugin->util->HealthBar($ev->getEntity()) . "     " . $this->plugin->util->ManaBar($ev->getEntity()));
            else
                $ev->getEntity()->sendTip("\n\n\n\n\n\n" . $this->plugin->util->HealthBar($ev->getEntity()) . "     " . $this->plugin->util->ManaBar($ev->getEntity()));
        }
        if ($ev instanceof EntityDamageByEntityEvent) {
            $player = $ev->getDamager();
            $target = $ev->getEntity();
            if ($player instanceof Player and $target instanceof Player) {// 플레이어 => 플레이어 가격
                $this->plugin->fixWeapon($player);
                $this->plugin->fixArmor($target);
                if (!$this->plugin->HotbarSystem->getData("유저전투") or $this->plugin->Equipments->getWeapon($player) == null) {// 유저전투가 금지되어있을때
                    if (!$player->isOp()) $ev->setCancelled(true);
                } else {
                    if ($ev->isCancelled()) return;
                    $this->plugin->hit[$target->getId()] = $player;
                    //source...
                }
            } elseif ($player instanceof Player and ($target instanceof MonsterBase or $target instanceof PersonBase)) {// 플레이어 => 몬스터 가격
                $this->plugin->fixWeapon($player);
                if (!$this->plugin->HotbarSystem->getData("유저사냥") || $this->plugin->Equipments->getWeapon($player) == null) {// 무기가 없거나 사냥이 금지되어있을떄
                    if (!$player->isOp()) {
                        $ev->setCancelled(true);
                        if ($this->plugin->Equipments->getWeapon($player) == false) $player->sendPopup("§l§c공격실패! 무기가 없습니다!");
                        else $player->sendPopup("§l§c운영시스템에 의해 사냥할 수 없습니다.");
                    } else {
                        if ($ev->isCancelled()) return;
                        $this->HitMonsterEvent($player, $target, $ev);
                    }
                } else {
                    if ($ev->isCancelled()) return;
                    if ($target->getLv() - $this->plugin->util->getLevel($player->getName()) >= 15) {
                        $player->sendPopup("§l§cMISS! 레벨 차이가 너무 큽니다!");
                        return;
                    }
                    $this->HitMonsterEvent($player, $target, $ev);
                    //source...
                }
            } elseif ($player instanceof MonsterBase or $player instanceof PersonBase and $target instanceof Player) {// 몬스터 => 플레이어 가격
                if ($this->plugin->skill->isUsingSkill($target->getName(), "순섬")) {
                    $ev->setCancelled(true);
                    return false;
                }
                $this->plugin->fixArmor($target);
                $this->plugin->util->war($target->getName());
                $damage = $this->plugin->getMonsterDamage($target, $player);
                if ($this->plugin->Party->isParty($target->getName())) {
                    foreach ($this->plugin->Party->getPartyMember_($this->plugin->Party->getParty($target->getName())) as $member) {
                        if ($this->plugin->skill->isSkill($member, "프리스트의 오라") && $this->plugin->skill->getSkillLevel($member, "프리스트의 오라") > 0) {
                            if (($o = $target->getServer()->getPlayer($member)) instanceof Player && $o->distance($target) <= 9) {
                                $damage *= 1 / ($this->plugin->skill->getSkillInfo("프리스트의 오라", $this->plugin->skill->getSkillLevel($member, "프리스트의 오라")));
                            }
                        }
                    }
                }
                $ev->setBaseDamage($damage);
                //source...
            } else {// 그외의 엔티티에 작용
                if ($player instanceof Player && !$player->isOp()) {
                    $ev->setCancelled(true);
                };
            }
        } else {// 엔티티가 아닌 다른 요인에 의한 작용
            if ($ev->getEntity() instanceof Player) {
                $this->plugin->fixArmor($ev->getEntity());
                return;
            } else $this->plugin->setNameTag($ev->getEntity());
        }
    }

    private function HitMonsterEvent($player, $target, $ev) {
        if ($ev->isCancelled())
            return false;
        $this->plugin->util->hitheal($player);
        $this->plugin->setNameTag($target);
        $this->plugin->hit[$target->getId()] = $player;
        $this->plugin->knockback[$target->getId()] = "true";
        $this->plugin->util->war($player->getName());
        if ($target->getTarget() == null) {
            $target->setTarget($player);
            $ev->setKnockBack(0.0);
        } else {
            if ($target->onGround) {
                $ev->setKnockBack(0.4);
                $target->punch();
            } else {
                $ev->setKnockBack(0.0);
            }
        }
        //if(isset($this->plugin->skill->Wizard_Active_2[$player->getId()]) && in_array($target, $this->plugin->skill->Wizard_Active_2[$player->getId()]))
        $damage = $this->plugin->getPlayerDamage($player, $target);
        $damage = $this->plugin->skill->onDamage($player, $target, $ev, $damage);
        $ev->setBaseDamage($damage);
        //source...
    }

    public function onDeath(PlayerDeathEvent $ev) {
        $ev->setKeepInventory(true);
        $ev->setDeathMessage(false);
    }

    public function onCraft(CraftItemEvent $ev) {
        $ev->setCancelled(true);
    }

    public function onHeal(EntityRegainHealthEvent $ev) {
        if ($ev->getRegainReason() !== 3)
            $ev->setCancelled(true);
    }
}
